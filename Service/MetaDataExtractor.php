<?php


namespace Tzunghaor\SettingsBundle\Service;


use Doctrine\Common\Annotations\Reader;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\PropertyInfo\Type;
use Tzunghaor\SettingsBundle\Annotation\Setting;
use Tzunghaor\SettingsBundle\Exception\SettingsException;
use Tzunghaor\SettingsBundle\Form\BoolType;
use Tzunghaor\SettingsBundle\Model\SectionMetaData;
use Tzunghaor\SettingsBundle\Model\SettingMetaData;

/**
 * Extracts metadata from setting section classes
 *
 * @internal it is not meant to be used outside of TzunghaorSettingsBundle
 */
class MetaDataExtractor
{
    /**
     * @var Reader
     */
    private $annotationReader;

    /**
     * @var PropertyInfoExtractorInterface
     */
    private $propertyInfo;

    public function __construct(
        Reader $annotationReader,
        PropertyInfoExtractorInterface $propertyInfo
    ) {
        $this->annotationReader = $annotationReader;
        $this->propertyInfo = $propertyInfo;
    }

    /**
     * @param string $sectionName
     * @param string $sectionClass
     *
     * @return SectionMetaData
     *
     * @throws SettingsException
     * @throws \ReflectionException
     */
    public function createSectionMetaData(string $sectionName, string $sectionClass): SectionMetaData
    {
        $settingsMetaArray = [];

        $reflectionClass = new \ReflectionClass($sectionClass);

        [$sectionTitle, $sectionDescription] = $this->extractTitleDescription($reflectionClass->getDocComment());
        $sectionTitle = empty($sectionTitle) ? $sectionName : $sectionTitle;

        $defaultType = new Type('string');

        // collect properties, including ancestor classes private properties
        $currentReflectionClass = $reflectionClass;
        $reflectionProperties = $currentReflectionClass->getProperties();
        while ($currentReflectionClass = $currentReflectionClass->getParentClass()) {
            $reflectionProperties = array_merge($reflectionProperties, $currentReflectionClass->getProperties());
        }
        foreach ($reflectionProperties as $reflectionProperty) {
            // extract data type
            $propertyName = $reflectionProperty->getName();
            // this happens when we meet an inherited public/protected property in the ancestor class again - can skip
            if (array_key_exists($propertyName, $settingsMetaArray)) {
                continue;
            }

            $propertyAnnotations = $this->annotationReader->getPropertyAnnotations($reflectionProperty);

            $settingLabel = null;
            $settingHelp = null;
            $dataType = null;
            $formType = null;
            $formEntryType = null;
            $formOptions = [];
            $isEnum = false;

            foreach ($propertyAnnotations as $annotation) {
                if ($annotation instanceof Setting) {
                    $formType = $annotation->formType;
                    $formEntryType = $annotation->formEntryType;
                    $formOptions = $annotation->formOptions ?? $formOptions;
                    if (is_array($annotation->enum)) {
                        $formType = $formType ?? ChoiceType::class;
                        $formOptions['choices'] = $formOptions['choices'] ?? array_combine($annotation->enum, $annotation->enum);
                        $isEnum = true;
                    }
                    $settingLabel = $annotation->label;
                    $settingHelp = $annotation->help;
                    $dataType = $dataType ?? $this->extractDataType($annotation->dataType);
                }
            }

            if ($dataType === null) {
                $dataTypes = $this->propertyInfo->getTypes($sectionClass, $propertyName);

                if ($dataTypes === null) {
                    $dataType = $defaultType;
                } elseif (count($dataTypes) === 1) {
                    $dataType = $dataTypes[0];
                } else {
                    throw new SettingsException(sprintf('Multiple types are not supported for setting %s in %s',
                                                        $propertyName, $sectionClass));
                }
            }

            if ($isEnum && $dataType->isCollection()) {
                $formOptions['multiple'] = $formOptions['multiple'] ?? true;
            }

            $settingLabel = $settingLabel ??
                trim($this->propertyInfo->getShortDescription($sectionClass, $propertyName));
            $settingHelp = $settingHelp ??
                (string) $this->propertyInfo->getLongDescription($sectionClass, $propertyName);
            $settingLabel = empty($settingLabel) ? $propertyName : $settingLabel;

            $formType = $formType ?? $this->getFormTypeByDataType($dataType);

            if ($formType === CollectionType::class) {
                $formOptions = $this->getCollectionFormOptions($dataType, $formEntryType, $formOptions);
            }

            $settingsMetaArray[$propertyName] = new SettingMetaData(
                $propertyName,
                $dataType,
                $formType,
                $formOptions,
                $settingLabel,
                $settingHelp
            );
        }

        return new SectionMetaData($sectionName, $sectionTitle, $sectionClass, $sectionDescription, $settingsMetaArray);
    }

    /**
     * Returns the default form type to be used for the given data type.
     *
     * @param Type $dataType
     *
     * @return string FQCN of form type
     */
    private function getFormTypeByDataType(Type $dataType): string
    {
        return $dataType->isCollection() ? CollectionType::class : $this->getBaseFormTypeByDataType($dataType);
    }

    /**
     * Returns the default base form type (entry type in case of collection) to be used for the given data type
     *
     * @param Type $dataType
     *
     * @return string FQCN of form type
     */
    private function getBaseFormTypeByDataType(Type $dataType): string
    {
        switch ($dataType->getClassName()) {
            case \DateTime::class:
                return DateTimeType::class;
        }

        switch ($dataType->getBuiltinType()) {
            case 'bool':
                return BoolType::class;

            case 'int':
                return IntegerType::class;

            case 'float':
                return NumberType::class;

            default:
                return TextType::class;
        }
    }

    /**
     * Adds form options needed by collection type
     *
     * @param Type $dataType datatype of setting
     * @param string|null $formEntryType explicitly configured form entry type
     * @param array $formOptions form options so far - these values won't be overwritten
     *
     * @return array form options enriched with options for collection type
     */
    private function getCollectionFormOptions(Type $dataType, ?string $formEntryType, array& $formOptions): array
    {
        $formEntryType = $formEntryType ?? $this->getBaseFormTypeByDataType($dataType);

        $collectionFormOptions = [
            'allow_add' => true,
            'allow_delete' => true,
            'entry_type' => $formEntryType,
            'entry_options' => ['label' => false, 'row_attr' => ['class' => 'tzunghaor_settings_collection_row']],
        ];

        return array_merge($collectionFormOptions, $formOptions);
    }

    /**
     * Simple naive method to extract title and description from a docblock
     *
     * @param string $docBlock
     *
     * @return array
     */
    private function extractTitleDescription(string $docBlock): array
    {
        $docComment = trim($docBlock, "\t /");
        $sectionTitle = $sectionDescription = null;

        if ($docComment !== false) {
            $commentLines = explode("\n", $docComment);
            $isBeginning = true;
            $descriptionLines = [];

            foreach ($commentLines as $commentLine) {
                $commentLine = trim(ltrim($commentLine, "\t *"));
                if (empty($commentLine) || $commentLine[0] === '@') {
                    continue;
                }

                if ($isBeginning) {
                    $sectionTitle = $commentLine;
                    $isBeginning = false;
                } else {
                    $descriptionLines[] = $commentLine;
                }
            }

            $sectionDescription = implode("\n", $descriptionLines);
        }

        return [$sectionTitle, $sectionDescription];
    }

    /**
     * Simple naive method to extract data type from a type definition string (e.g. "\DateTime[]")
     *
     * @param string|null $dataTypeStringIn
     *
     * @return Type|null
     *
     * @throws SettingsException
     */
    private function extractDataType(?string $dataTypeStringIn): ?Type
    {
        if ($dataTypeStringIn === null || empty($dataTypeString = trim($dataTypeStringIn))) {
            return null;
        }

        $isCollection = false;
        if (substr($dataTypeString, -2) === '[]') {
            $isCollection = true;
            $dataTypeString = substr($dataTypeString, 0, -2);
        }

        if (in_array($dataTypeString, Type::$builtinTypes, true)) {
            return new Type($dataTypeString, false, null, $isCollection);
        }

        if (!class_exists($dataTypeString)) {
            throw new SettingsException(sprintf('unknown @Setting(dataType="%s")', $dataTypeStringIn));
        }

        return new Type(Type::BUILTIN_TYPE_OBJECT, false, $dataTypeString, $isCollection);
    }
}