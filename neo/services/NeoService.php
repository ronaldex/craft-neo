<?php
namespace Craft;

class NeoService extends BaseApplicationComponent
{
	private $_blockTypesById;
	private $_groupsById;
	private $_blockTypesByFieldId;
	private $_groupsByFieldId;
	private $_fetchedAllBlockTypesForFieldId;
	private $_fetchedAllGroupsForFieldId;
	private $_blockTypeRecordsById;
	private $_groupRecordsById;
	private $_blockRecordsById;
	private $_uniqueBlockTypeAndFieldHandles;
	private $_parentNeoFields;

	public $currentSavingBlockType;

	public function getBlockTypesByFieldId($fieldId, $indexBy = null)
	{
		if(empty($this->_fetchedAllBlockTypesForFieldId[$fieldId]))
		{
			$this->_blockTypesByFieldId[$fieldId] = [];

			$results = $this->_createBlockTypeQuery()
				->where('fieldId = :fieldId', array(':fieldId' => $fieldId))
				->queryAll();

			foreach($results as $result)
			{
				$blockType = new Neo_BlockTypeModel($result);
				$this->_blockTypesById[$blockType->id] = $blockType;
				$this->_blockTypesByFieldId[$fieldId][] = $blockType;
			}

			$this->_fetchedAllBlockTypesForFieldId[$fieldId] = true;
		}

		if($indexBy)
		{
			$blockTypes = [];

			foreach($this->_blockTypesByFieldId[$fieldId] as $blockType)
			{
				$blockTypes[$blockType->$indexBy] = $blockType;
			}

			return $blockTypes;
		}

		return $this->_blockTypesByFieldId[$fieldId];
	}

	public function getGroupsByFieldId($fieldId, $indexBy = null)
	{
		if(empty($this->_fetchedAllGroupsForFieldId[$fieldId]))
		{
			$this->_groupsByFieldId[$fieldId] = [];

			$results = $this->_createGroupQuery()
				->where('fieldId = :fieldId', array(':fieldId' => $fieldId))
				->queryAll();

			foreach($results as $result)
			{
				$group = new Neo_GroupModel($result);
				$this->_groupsById[$group->id] = $group;
				$this->_groupsByFieldId[$fieldId][] = $group;
			}

			$this->_fetchedAllGroupsForFieldId[$fieldId] = true;
		}

		if($indexBy)
		{
			$groups = [];

			foreach($this->_groupsByFieldId[$fieldId] as $group)
			{
				$groups[$group->$indexBy] = $group;
			}

			return $groups;
		}

		return $this->_groupsByFieldId[$fieldId];
	}

	public function getBlockTypeById($blockTypeId)
	{
		if(!isset($this->_blockTypesById) || !array_key_exists($blockTypeId, $this->_blockTypesById))
		{
			$result = $this->_createBlockTypeQuery()
				->where('id = :id', array(':id' => $blockTypeId))
				->queryRow();

			if($result)
			{
				$blockType = new Neo_BlockTypeModel($result);
			}
			else
			{
				$blockType = null;
			}

			$this->_blockTypesById[$blockTypeId] = $blockType;
		}

		return $this->_blockTypesById[$blockTypeId];
	}

	public function validateBlockType(Neo_BlockTypeModel $blockType, $validateUniques = true)
	{
		$validates = true;

		$blockTypeRecord = $this->_getBlockTypeRecord($blockType);

		$blockTypeRecord->fieldId = $blockType->fieldId;
		$blockTypeRecord->name    = $blockType->name;
		$blockTypeRecord->handle  = $blockType->handle;

		$blockTypeRecord->validateUniques = $validateUniques;

		if(!$blockTypeRecord->validate())
		{
			$validates = false;
			$blockType->addErrors($blockTypeRecord->getErrors());
		}

		$blockTypeRecord->validateUniques = true;

		return $validates;
	}

	public function saveBlockType(Neo_BlockTypeModel $blockType, $validate = true)
	{
		if(!$validate || $this->validateBlockType($blockType))
		{
			$this->currentSavingBlockType = $blockType;

			$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
			try
			{
				// Get the block type record
				$blockTypeRecord = $this->_getBlockTypeRecord($blockType);
				$isNewBlockType = $blockType->isNew();
				$oldBlockType = $isNewBlockType ? null : Neo_BlockTypeModel::populateModel($blockTypeRecord);

				// Is there a new field layout?
				$fieldLayout = $blockType->getFieldLayout();

				if(!$fieldLayout->id)
				{
					// Delete the old one
					if(!$isNewBlockType && $oldBlockType->fieldLayoutId)
					{
						craft()->fields->deleteLayoutById($oldBlockType->fieldLayoutId);
					}

					// Save the new one
					craft()->fields->saveLayout($fieldLayout);

					// Update the entry type record/model with the new layout ID
					$blockType->fieldLayoutId = $fieldLayout->id;
					$blockTypeRecord->fieldLayoutId = $fieldLayout->id;
				}

				// Set the basic info on the new block type record
				$blockTypeRecord->fieldId     = $blockType->fieldId;
				$blockTypeRecord->name        = $blockType->name;
				$blockTypeRecord->handle      = $blockType->handle;
				$blockTypeRecord->sortOrder   = $blockType->sortOrder;
				$blockTypeRecord->maxBlocks   = $blockType->maxBlocks;
				$blockTypeRecord->childBlocks = $blockType->childBlocks;
				$blockTypeRecord->topLevel    = $blockType->topLevel;

				// Save it, minus the field layout for now
				$blockTypeRecord->save(false);

				if($isNewBlockType)
				{
					// Set the new ID on the model
					$blockType->id = $blockTypeRecord->id;
				}

				// Update the block type with the field layout ID
				$blockTypeRecord->save(false);

				if($transaction !== null)
				{
					$transaction->commit();
				}
			}
			catch(\Exception $e)
			{
				if($transaction !== null)
				{
					$transaction->rollback();
				}

				throw $e;
			}

			$this->currentSavingBlockType = null;

			return true;
		}
		else
		{
			return false;
		}
	}

	public function saveGroup(Neo_GroupModel $group)
	{
		$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
		try
		{
			$groupRecord = new Neo_GroupRecord();
			$groupRecord->fieldId   = $group->fieldId;
			$groupRecord->name      = $group->name;
			$groupRecord->sortOrder = $group->sortOrder;

			$groupRecord->save(false);

			$group->id = $groupRecord->id;

			if($transaction !== null)
			{
				$transaction->commit();
			}
		}
		catch(\Exception $e)
		{
			if($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}

		return true;
	}

	public function deleteBlockType(Neo_BlockTypeModel $blockType)
	{
		$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
		try
		{
			// First delete the blocks of this type
			$blockIds = craft()->db->createCommand()
				->select('id')
				->from('neoblocks')
				->where(array('typeId' => $blockType->id))
				->queryColumn();

			$this->deleteBlockById($blockIds);

			// Delete the field layout
			craft()->fields->deleteLayoutById($blockType->fieldLayoutId);

			// Finally delete the actual block type
			$affectedRows = craft()->db->createCommand()->delete('neoblocktypes', array('id' => $blockType->id));

			if($transaction !== null)
			{
				$transaction->commit();
			}

			return (bool) $affectedRows;
		}
		catch(\Exception $e)
		{
			if($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}
	}

	public function deleteGroupsByFieldId($fieldId)
	{
		$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
		try
		{
			$affectedRows = craft()->db->createCommand()
				->delete('neogroups', array('fieldId' => $fieldId));

			if($transaction !== null)
			{
				$transaction->commit();
			}

			return (bool) $affectedRows;
		}
		catch(\Exception $e)
		{
			if($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}
	}

	public function validateFieldSettings(Neo_SettingsModel $settings)
	{
		$validates = true;

		$this->_uniqueBlockTypeAndFieldHandles = [];

		$uniqueAttributes = array('name', 'handle');
		$uniqueAttributeValues = [];

		foreach($settings->getBlockTypes() as $blockType)
		{
			if(!$this->validateBlockType($blockType, false))
			{
				// Don't break out of the loop because we still want to get validation errors for the remaining block
				// types.
				$validates = false;
			}

			// Do our own unique name/handle validation, since the DB-based validation can't be trusted when saving
			// multiple records at once
			foreach($uniqueAttributes as $attribute)
			{
				$value = $blockType->$attribute;

				if($value && (!isset($uniqueAttributeValues[$attribute]) || !in_array($value, $uniqueAttributeValues[$attribute])))
				{
					$uniqueAttributeValues[$attribute][] = $value;
				}
				else
				{
					$blockType->addError($attribute, Craft::t('{attribute} "{value}" has already been taken.', array(
						'attribute' => $blockType->getAttributeLabel($attribute),
						'value'     => HtmlHelper::encode($value)
					)));

					$validates = false;
				}
			}
		}

		return $validates;
	}

	public function saveSettings(Neo_SettingsModel $settings, $validate = true)
	{
		if(!$validate || $this->validateFieldSettings($settings))
		{
			$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
			try
			{
				$neoField = $settings->getField();

				// Delete the old block types first, in case there's a handle conflict with one of the new ones
				$oldBlockTypes = $this->getBlockTypesByFieldId($neoField->id);
				$oldBlockTypesById = [];

				foreach($oldBlockTypes as $blockType)
				{
					$oldBlockTypesById[$blockType->id] = $blockType;
				}

				foreach($settings->getBlockTypes() as $blockType)
				{
					if(!$blockType->isNew())
					{
						unset($oldBlockTypesById[$blockType->id]);
					}
				}

				foreach($oldBlockTypesById as $blockType)
				{
					$this->deleteBlockType($blockType);
				}

				$this->deleteGroupsByFieldId($neoField->id);

				// Save the new ones
				$sortOrder = 0;

				foreach($settings->getBlockTypes() as $blockType)
				{
					$sortOrder++;
					$blockType->fieldId = $neoField->id;

					if(!$blockType->sortOrder)
					{
						$blockType->sortOrder = $sortOrder;
					}

					$this->saveBlockType($blockType, false);
				}

				foreach($settings->getGroups() as $group)
				{
					$sortOrder++;
					$group->fieldId = $neoField->id;

					if(!$group->sortOrder)
					{
						$group->sortOrder = $sortOrder;
					}

					$this->saveGroup($group);
				}

				if($transaction !== null)
				{
					$transaction->commit();
				}

				// Update our cache of this field's block types
				$this->_blockTypesByFieldId[$settings->getField()->id] = $settings->getBlockTypes();

				return true;
			}
			catch(\Exception $e)
			{
				if($transaction !== null)
				{
					$transaction->rollback();
				}

				throw $e;
			}
		}
		else
		{
			return false;
		}
	}

	public function deleteNeoField(FieldModel $neoField)
	{
		$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
		try
		{
			// Delete the block types
			$blockTypes = $this->getBlockTypesByFieldId($neoField->id);

			foreach($blockTypes as $blockType)
			{
				$this->deleteBlockType($blockType);
			}

			if($transaction !== null)
			{
				$transaction->commit();
			}

			return true;
		}
		catch(\Exception $e)
		{
			if($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}
	}

	public function getBlockById($blockId, $localeId = null)
	{
		return craft()->elements->getElementById($blockId, Neo_ElementType::NeoBlock, $localeId);
	}

	public function validateBlock(Neo_BlockModel $block)
	{
		$block->clearErrors();

		$blockRecord = $this->_getBlockRecord($block);

		$blockRecord->fieldId   = $block->fieldId;
		$blockRecord->ownerId   = $block->ownerId;
		$blockRecord->typeId    = $block->typeId;
		$blockRecord->sortOrder = $block->sortOrder;
		$blockRecord->collapsed = $block->collapsed;

		$blockRecord->validate();
		$block->addErrors($blockRecord->getErrors());

		if(!craft()->content->validateContent($block))
		{
			$block->addErrors($block->getContent()->getErrors());
		}

		return !$block->hasErrors();
	}

	public function saveBlock(Neo_BlockModel $block, $validate = true)
	{
		if(!$validate || $this->validateBlock($block))
		{
			$blockRecord = $this->_getBlockRecord($block);
			$isNewBlock = $blockRecord->isNewRecord();

			$blockRecord->fieldId     = $block->fieldId;
			$blockRecord->ownerId     = $block->ownerId;
			$blockRecord->ownerLocale = $block->ownerLocale;
			$blockRecord->typeId      = $block->typeId;
			$blockRecord->sortOrder   = $block->sortOrder;
			$blockRecord->collapsed   = $block->collapsed;
			$blockRecord->level       = $block->level;

			$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
			try
			{
				if(craft()->elements->saveElement($block, false))
				{
					if($isNewBlock)
					{
						$blockRecord->id = $block->id;
					}

					$blockRecord->save(false);

					if($transaction !== null)
					{
						$transaction->commit();
					}

					return true;
				}
			}
			catch(\Exception $e)
			{
				if($transaction !== null)
				{
					$transaction->rollback();
				}

				throw $e;
			}
		}

		return false;
	}

	public function saveBlockCollapse(Neo_BlockModel $block)
	{
		$tableName = (new Neo_BlockRecord())->getTableName();

		craft()->db->createCommand()->update(
			$tableName,
			array('collapsed' => $block->collapsed ? 1 : 0),
			'id = :id',
			array(':id' => $block->id)
		);

		return true;
	}

	public function deleteBlockById($blockIds)
	{
		if(!$blockIds)
		{
			return false;
		}

		if(!is_array($blockIds))
		{
			$blockIds = array($blockIds);
		}

		// Pass this along to ElementsService for the heavy lifting
		return craft()->elements->deleteElementById($blockIds);
	}

	public function saveField(NeoFieldType $fieldType)
	{
		$owner = $fieldType->element;
		$field = $fieldType->model;
		$blocks = $owner->getContent()->getAttribute($field->handle);

		if($blocks === null)
		{
			return true;
		}

		if(!is_array($blocks))
		{
			$blocks = [];
		}

		$transaction = craft()->db->getCurrentTransaction() === null ? craft()->db->beginTransaction() : null;
		try
		{
			// First thing's first. Let's make sure that the blocks for this field/owner respect the field's translation
			// setting
			$this->_applyFieldTranslationSetting($owner, $field, $blocks);

			$blockIds = [];

			foreach($blocks as $block)
			{
				$block->ownerId = $owner->id;
				$block->ownerLocale = ($field->translatable ? $owner->locale : null);

				$this->saveBlock($block, false);

				$blockIds[] = $block->id;
			}

			// Get the IDs of blocks that are row deleted
			$deletedBlockConditions = array('and',
				'ownerId = :ownerId',
				'fieldId = :fieldId',
				array('not in', 'id', $blockIds)
			);

			$deletedBlockParams = array(
				':ownerId' => $owner->id,
				':fieldId' => $field->id
			);

			if($field->translatable)
			{
				$deletedBlockConditions[] = 'ownerLocale  = :ownerLocale';
				$deletedBlockParams[':ownerLocale'] = $owner->locale;
			}

			$deletedBlockIds = craft()->db->createCommand()
				->select('id')
				->from('neoblocks')
				->where($deletedBlockConditions, $deletedBlockParams)
				->queryColumn();

			$this->deleteBlockById($deletedBlockIds);

			if($transaction !== null)
			{
				$transaction->commit();
			}
		}
		catch(\Exception $e)
		{
			if($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}

		return true;
	}

	public function getParentNeoField(FieldModel $neoField)
	{
		if(!isset($this->_parentNeoFields) || !array_key_exists($neoField->id, $this->_parentNeoFields))
		{
			// Does this Neo field belong to another one?
			$parentNeoFieldId = craft()->db->createCommand()
				->select('fields.id')
				->from('fields fields')
				->join('neoblocktypes blocktypes', 'blocktypes.fieldId = fields.id')
				->join('fieldlayoutfields fieldlayoutfields', 'fieldlayoutfields.layoutId = blocktypes.fieldLayoutId')
				->where('fieldlayoutfields.fieldId = :neoFieldId', array(':neoFieldId' => $neoField->id))
				->queryScalar();

			if($parentNeoFieldId)
			{
				$this->_parentNeoFields[$neoField->id] = craft()->fields->getFieldById($parentNeoFieldId);
			}
			else
			{
				$this->_parentNeoFields[$neoField->id] = null;
			}
		}

		return $this->_parentNeoFields[$neoField->id];
	}

	public function requirePlugin($plugin)
	{
		if(!craft()->plugins->getPlugin($plugin))
		{
			$message = Craft::t("The plugin \"{plugin}\" is required for Neo to use this functionality.", array(
				'plugin' => $plugin
			));

			throw new Exception($message);
		}
	}

	private function _createBlockTypeQuery()
	{
		return craft()->db->createCommand()
			->select('id, fieldId, fieldLayoutId, name, handle, maxBlocks, childBlocks, topLevel, sortOrder')
			->from('neoblocktypes')
			->order('sortOrder');
	}

	private function _createGroupQuery()
	{
		return craft()->db->createCommand()
			->select('id, fieldId, name, sortOrder')
			->from('neogroups')
			->order('sortOrder');
	}

	private function _getBlockTypeRecord(Neo_BlockTypeModel $blockType)
	{
		if(!$blockType->isNew())
		{
			$blockTypeId = $blockType->id;

			if(!isset($this->_blockTypeRecordsById) || !array_key_exists($blockTypeId, $this->_blockTypeRecordsById))
			{
				$this->_blockTypeRecordsById[$blockTypeId] = Neo_BlockTypeRecord::model()->findById($blockTypeId);

				if(!$this->_blockTypeRecordsById[$blockTypeId])
				{
					throw new Exception(Craft::t('No block type exists with the ID “{id}”.', array('id' => $blockTypeId)));
				}
			}

			return $this->_blockTypeRecordsById[$blockTypeId];
		}
		else
		{
			return new Neo_BlockTypeRecord();
		}
	}

	private function _getBlockRecord(Neo_BlockModel $block)
	{
		$blockId = $block->id;

		if($blockId)
		{
			if(!isset($this->_blockRecordsById) || !array_key_exists($blockId, $this->_blockRecordsById))
			{
				$this->_blockRecordsById[$blockId] = Neo_BlockRecord::model()->with('element')->findById($blockId);

				if(!$this->_blockRecordsById[$blockId])
				{
					throw new Exception(Craft::t('No block exists with the ID “{id}”.', array('id' => $blockId)));
				}
			}

			return $this->_blockRecordsById[$blockId];
		}
		else
		{
			return new Neo_BlockRecord();
		}
	}
	
	private function _applyFieldTranslationSetting($owner, $field, $blocks)
	{
		// Does it look like any work is needed here?
		$applyNewTranslationSetting = false;

		foreach($blocks as $block)
		{
			if($block->id && (
				($field->translatable && !$block->ownerLocale) ||
				(!$field->translatable && $block->ownerLocale)
			))
			{
				$applyNewTranslationSetting = true;
				break;
			}
		}

		if($applyNewTranslationSetting)
		{
			// Get all of the blocks for this field/owner that use the other locales, whose ownerLocale attribute is set
			// incorrectly
			$blocksInOtherLocales = [];

			$criteria = craft()->elements->getCriteria(Neo_ElementType::NeoBlock);
			$criteria->fieldId = $field->id;
			$criteria->ownerId = $owner->id;
			$criteria->status = null;
			$criteria->localeEnabled = null;
			$criteria->limit = null;

			if($field->translatable)
			{
				$criteria->ownerLocale = ':empty:';
			}

			foreach(craft()->i18n->getSiteLocaleIds() as $localeId)
			{
				if($localeId == $owner->locale)
				{
					continue;
				}

				$criteria->locale = $localeId;

				if(!$field->translatable)
				{
					$criteria->ownerLocale = $localeId;
				}

				$blocksInOtherLocale = $criteria->find();

				if($blocksInOtherLocale)
				{
					$blocksInOtherLocales[$localeId] = $blocksInOtherLocale;
				}
			}

			if($blocksInOtherLocales)
			{
				if($field->translatable)
				{
					$newBlockIds = [];

					// Duplicate the other-locale blocks so each locale has their own unique set of blocks
					foreach($blocksInOtherLocales as $localeId => $blocksInOtherLocale)
					{
						foreach($blocksInOtherLocale as $blockInOtherLocale)
						{
							$originalBlockId = $blockInOtherLocale->id;

							$blockInOtherLocale->id = null;
							$blockInOtherLocale->getContent()->id = null;
							$blockInOtherLocale->ownerLocale = $localeId;
							$this->saveBlock($blockInOtherLocale, false);

							$newBlockIds[$originalBlockId][$localeId] = $blockInOtherLocale->id;
						}
					}

					// Duplicate the relations, too.  First by getting all of the existing relations for the original
					// blocks
					$relations = craft()->db->createCommand()
						->select('fieldId, sourceId, sourceLocale, targetId, sortOrder')
						->from('relations')
						->where(array('in', 'sourceId', array_keys($newBlockIds)))
						->queryAll();

					if($relations)
					{
						// Now duplicate each one for the other locales' new blocks
						$rows = [];

						foreach($relations as $relation)
						{
							$originalBlockId = $relation['sourceId'];

							// Just to be safe...
							if(isset($newBlockIds[$originalBlockId]))
							{
								foreach($newBlockIds[$originalBlockId] as $localeId => $newBlockId)
								{
									$rows[] = array($relation['fieldId'], $newBlockId, $relation['sourceLocale'], $relation['targetId'], $relation['sortOrder']);
								}
							}
						}

						craft()->db->createCommand()->insertAll('relations', array('fieldId', 'sourceId', 'sourceLocale', 'targetId', 'sortOrder'), $rows);
					}
				}
				else
				{
					// Delete all of these blocks
					$blockIdsToDelete = [];

					foreach($blocksInOtherLocales as $localeId => $blocksInOtherLocale)
					{
						foreach($blocksInOtherLocale as $blockInOtherLocale)
						{
							$blockIdsToDelete[] = $blockInOtherLocale->id;
						}
					}

					$this->deleteBlockById($blockIdsToDelete);
				}
			}
		}
	}
}
