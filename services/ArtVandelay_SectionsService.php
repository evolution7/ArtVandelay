<?php
namespace Craft;

/**
 * Class ArtVandelay_SectionsService
 */
class ArtVandelay_SectionsService extends BaseApplicationComponent
{

    //==============================================================================================================
    //=================================================  EXPORT  ===================================================
    //==============================================================================================================

    /**
     * @param SectionModel[] $sections
     * @param array|null $allowedEntryTypeIds
     * @return array
     */
    public function export(array $sections, array $allowedEntryTypeIds = null)
    {
        $sectionDefinitions = array();

        foreach ($sections as $section) {
            $sectionDefinitions[$section->handle] = $this->getSectionDefinition($section, $allowedEntryTypeIds);
        }

        return $sectionDefinitions;
    }

    /**
     * @param SectionModel $section
     * @param $allowedEntryTypeIds
     * @return array
     */
    private function getSectionDefinition(SectionModel $section, $allowedEntryTypeIds)
    {
        return array(
            'name' => $section->name,
            'type' => $section->type,
            'hasUrls' => $section->hasUrls,
            'template' => $section->template,
            'maxLevels' => $section->maxLevels,
            'enableVersioning' => $section->enableVersioning,
            'locales' => $this->getLocaleDefinitions($section->getLocales()),
            'entryTypes' => $this->getEntryTypeDefinitions($section->getEntryTypes(), $allowedEntryTypeIds)
        );
    }

    /**
     * @param SectionLocaleModel[] $locales
     * @return array
     */
    private function getLocaleDefinitions(array $locales)
    {
        $localeDefinitions = array();

        foreach ($locales as $locale) {
            $localeDefinitions[$locale->locale] = $this->getLocaleDefinition($locale);
        }

        return $localeDefinitions;
    }

    /**
     * @param SectionLocaleModel $locale
     * @return array
     */
    private function getLocaleDefinition(SectionLocaleModel $locale)
    {
        return array(
            'enabledByDefault' => $locale->enabledByDefault,
            'urlFormat' => $locale->urlFormat,
            'nestedUrlFormat' => $locale->nestedUrlFormat
        );
    }

    /**
     * @param array $entryTypes
     * @param $allowedEntryTypeIds
     * @return array
     */
    private function getEntryTypeDefinitions(array $entryTypes, $allowedEntryTypeIds)
    {
        $entryTypeDefinitions = array();

        foreach ($entryTypes as $entryType) {
            if ($allowedEntryTypeIds === null || in_array($entryType->id, $allowedEntryTypeIds)) {
                $entryTypeDefinitions[$entryType->handle] = $this->getEntryTypeDefinition($entryType);
            }
        }

        return $entryTypeDefinitions;
    }

    /**
     * @param EntryTypeModel $entryType
     * @return array
     */
    private function getEntryTypeDefinition(EntryTypeModel $entryType)
    {
        return array(
            'name' => $entryType->name,
            'hasTitleField' => $entryType->hasTitleField,
            'titleLabel' => $entryType->titleLabel,
            'titleFormat' => $entryType->titleFormat,
            'fieldLayout' => $this->getFieldLayoutDefinition($entryType->getFieldLayout())
        );
    }

    /**
     * @param FieldLayoutModel $fieldLayout
     * @return array
     */
    private function getFieldLayoutDefinition(FieldLayoutModel $fieldLayout)
    {
        if ($fieldLayout->getTabs()) {
            $tabDefinitions = array();

            foreach ($fieldLayout->getTabs() as $tab) {
                $tabDefinitions[$tab->name] = $this->getFieldLayoutFieldsDefinition($tab->getFields());
            }

            return array('tabs' => $tabDefinitions);
        }

        return array('fields' => $this->getFieldLayoutFieldsDefinition($fieldLayout->getFields()));
    }

    /**
     * @param FieldLayoutFieldModel[] $fields
     * @return array
     */
    private function getFieldLayoutFieldsDefinition(array $fields)
    {
        $fieldDefinitions = array();

        foreach ($fields as $field) {
            $fieldDefinitions[$field->getField()->handle] = $field->required;
        }

        return $fieldDefinitions;
    }

    //==============================================================================================================
    //=================================================  IMPORT  ===================================================
    //==============================================================================================================

    /**
     * Attempt to import sections.
     *
     * @param array $sectionDefs
     *
     * @return ArtVandelay_ResultModel
     */
    public function import($sectionDefs)
    {
        $result = new ArtVandelay_ResultModel();

        if (empty($sectionDefs)) {
            // Ignore importing sections.
            return $result;
        }


        $sections = craft()->sections->getAllSections('handle');

        foreach ($sectionDefs as $sectionHandle => $sectionDef) {
            $section = array_key_exists($sectionHandle, $sections)
                ? $sections[$sectionHandle]
                : new SectionModel();

            $section->setAttributes(array(
                'handle' => $sectionHandle,
                'name' => $sectionDef['name'],
                'type' => $sectionDef['type'],
                'hasUrls' => $sectionDef['hasUrls'],
                'template' => $sectionDef['template'],
                'maxLevels' => $sectionDef['maxLevels'],
                'enableVersioning' => $sectionDef['enableVersioning']
            ));


            if (!array_key_exists('locales', $sectionDef)) {
                return $result->error('`sections[handle].locales` must be defined');
            }

            $locales = $section->getLocales();

            foreach ($sectionDef['locales'] as $localeId => $localeDef) {
                $locale = array_key_exists($localeId, $locales)
                    ? $locales[$localeId]
                    : new SectionLocaleModel();

                $locale->setAttributes(array(
                    'locale' => $localeId,
                    'enabledByDefault' => $localeDef['enabledByDefault'],
                    'urlFormat' => $localeDef['urlFormat'],
                    'nestedUrlFormat' => $localeDef['nestedUrlFormat']
                ));

                // Todo: Is this a hack? I don't see another way.
                // Todo: Might need a sorting order as well? It's NULL at the moment.
                craft()->db->createCommand()->insertOrUpdate('locales', array(
                    'locale' => $locale->locale
                ), array());

                $locales[$localeId] = $locale;
            }

            $section->setLocales($locales);

            // Create initial section record
            if (!$this->saveSection($section)) {
                return $result->error($section->getAllErrors());
            }


            $entryTypes = $section->getEntryTypes('handle');

            if (!array_key_exists('entryTypes', $sectionDef)) {
                return $result->error('`sections[handle].entryTypes` must exist be defined');
            }

            foreach ($sectionDef['entryTypes'] as $entryTypeHandle => $entryTypeDef) {
                $entryType = array_key_exists($entryTypeHandle, $entryTypes)
                    ? $entryTypes[$entryTypeHandle]
                    : new EntryTypeModel();

                $entryType->setAttributes(array(
                    'sectionId' => $section->id,
                    'handle' => $entryTypeHandle,
                    'name' => $entryTypeDef['name'],
                    'hasTitleField' => $entryTypeDef['hasTitleField'],
                    'titleLabel' => $entryTypeDef['titleLabel'],
                    'titleFormat' => $entryTypeDef['titleFormat']
                ));

                $fieldLayout = $this->_importFieldLayout($entryTypeDef['fieldLayout']);

                if ($fieldLayout !== null) {
                    $entryType->setFieldLayout($fieldLayout);

                    if (!craft()->sections->saveEntryType($entryType)) {
                        return $result->error($entryType->getAllErrors());
                    }
                } else {
                    // Todo: Too ambiguous.
                    return $result->error('Failed to import field layout.');
                }
            }

            // Save section via craft after entrytypes have been created
            if (!craft()->sections->saveSection($section)) {
                return $result->error($section->getAllErrors());
            }
        }

        return $result;
    }

    /**
     * Save the section manually if it is new to prevent craft from creating the default entry type
     * @param SectionModel $section
     * @return mixed
     */
    private function saveSection(SectionModel $section)
    {
        if ($section->type != 'single' && !$section->id) {
            $sectionRecord = new SectionRecord();

            // Shared attributes
            $sectionRecord->name = $section->name;
            $sectionRecord->handle = $section->handle;
            $sectionRecord->type = $section->type;
            $sectionRecord->enableVersioning = $section->enableVersioning;

            if (!$sectionRecord->save()) {
                $section->addErrors($sectionRecord->getErrors());
                return false;
            };
            $section->id = $sectionRecord->id;
            return true;
        }
        return craft()->sections->saveSection($section);
    }


    /**
     * Attempt to import a field layout.
     * @param array $fieldLayoutDef
     * @return FieldLayoutModel
     */
    private function _importFieldLayout(Array $fieldLayoutDef)
    {
        $layoutFields = array();
        $requiredFields = array();

        if (array_key_exists('tabs', $fieldLayoutDef)) {

            foreach ($fieldLayoutDef['tabs'] as $tabName => $tabDef) {
                $layoutTabFields = $this->getPrepareFieldLayout($tabDef);
                $requiredFields = array_merge($requiredFields, $layoutTabFields['required']);
                $layoutFields[$tabName] = $layoutTabFields['fields'];
            }
        } elseif (array_key_exists('fields', $fieldLayoutDef)) {
            $layoutTabFields = $this->getPrepareFieldLayout($fieldLayoutDef);
            $requiredFields = $layoutTabFields['required'];
            $layoutFields = $layoutTabFields['fields'];
        }

        $fieldLayout = craft()->fields->assembleLayout($layoutFields, $requiredFields);
        $fieldLayout->type = ElementType::Entry;

        return $fieldLayout;
    }

    /**
     * Get a prepared fieldLayout for the craft assembleLayout function
     * @param array $fieldLayoutDef
     * @return array
     */
    private function getPrepareFieldLayout(array $fieldLayoutDef)
    {
        $layoutFields = array();
        $requiredFields = array();

        foreach ($fieldLayoutDef as $fieldHandle => $required) {
            $field = craft()->fields->getFieldByHandle($fieldHandle);

            if ($field instanceof FieldModel) {
                $layoutFields[] = $field->id;

                if ($required) {
                    $requiredFields[] = $field->id;
                }
            }
        }

        return array(
            'fields' => $layoutFields,
            'required' => $requiredFields
        );
    }
}
