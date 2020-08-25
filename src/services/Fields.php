<?php

namespace benf\neo\services;

use benf\neo\elements\db\BlockQuery;
use benf\neo\models\BlockStructure;
use yii\base\Component;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\fields\BaseRelationField;
use craft\helpers\Html;
use craft\helpers\ArrayHelper;
use craft\helpers\ElementHelper;

use benf\neo\Plugin as Neo;
use benf\neo\Field;
use benf\neo\elements\Block;
use benf\neo\helpers\Memoize;
use benf\neo\tasks\DuplicateNeoStructureTask;

/**
 * Class Fields
 *
 * @package benf\neo\services
 * @author Spicy Web <craft@spicyweb.com.au>
 * @author Benjamin Fleming
 * @since 2.0.0
 */
class Fields extends Component
{
    
    private $_rebuildIfDeleted = false;
    
    /**
     * Performs validation on a Neo field.
     *
     * @param Field $field The field to validate.
     * @return bool Whether validation was successful.
     */
    public function validate(Field $field): bool
    {
        $isValid = true;
        
        $handles = [];
        
        foreach ($field->getBlockTypes() as $blockType) {
            $isBlockTypeValid = Neo::$plugin->blockTypes->validate($blockType, false);
            $isValid = $isValid && $isBlockTypeValid;
            
            if (isset($handles[$blockType->handle])) {
                $blockType->addError('handle', Craft::t('neo', "{label} \"{value}\" has already been taken.", [
                    'label' => $blockType->getAttributeLabel('handle'),
                    'value' => Html::encode($blockType->handle),
                ]));
                
                $isValid = false;
            } else {
                $handles[$blockType->handle] = true;
            }
        }
        
        return $isValid;
    }
    
    /**
     * Saves a Neo field's settings.
     *
     * @param Field $field The field to save.
     * @param bool $validate Whether to perform validation.
     * @return bool Whether saving was successful.
     * @throws \Throwable
     */
    public function save(Field $field, bool $validate = true): bool
    {
        $dbService = Craft::$app->getDb();
        
        $isValid = !$validate || $this->validate($field);
        
        if ($isValid) {
            $transaction = $dbService->beginTransaction();
            try {
                // Delete the old block types first, in case there's a handle conflict with one of the new ones
                $oldBlockTypes = Neo::$plugin->blockTypes->getByFieldId($field->id);
                $oldBlockTypesById = [];
                
                foreach ($oldBlockTypes as $blockType) {
                    $oldBlockTypesById[$blockType->id] = $blockType;
                }
                
                foreach ($field->getBlockTypes() as $blockType) {
                    if (!$blockType->getIsNew()) {
                        unset($oldBlockTypesById[$blockType->id]);
                    }
                }
                
                foreach ($oldBlockTypesById as $blockType) {
                    Neo::$plugin->blockTypes->delete($blockType);
                }

                // Delete any old groups that were removed
                $oldGroups = Neo::$plugin->blockTypes->getGroupsByFieldId($field->id);
                $oldGroupsById = [];

                foreach ($oldGroups as $blockTypeGroup) {
                    $oldGroupsById[$blockTypeGroup->id] = $blockTypeGroup;
                }

                foreach ($field->getGroups() as $blockTypeGroup) {
                    if (!$blockTypeGroup->getIsNew()) {
                        unset($oldGroupsById[$blockTypeGroup->id]);
                    }
                }

                foreach ($oldGroupsById as $blockTypeGroup) {
                    Neo::$plugin->blockTypes->deleteGroup($blockTypeGroup);
                }

                // Save the new block types and groups
                foreach ($field->getBlockTypes() as $blockType) {
                    $blockType->fieldId = $field->id;
                    Neo::$plugin->blockTypes->save($blockType, false);
                }
                
                foreach ($field->getGroups() as $blockTypeGroup) {
                    $blockTypeGroup->fieldId = $field->id;
                    Neo::$plugin->blockTypes->saveGroup($blockTypeGroup);
                }
                
                $transaction->commit();
                
                Memoize::$blockTypesByFieldId[$field->id] = $field->getBlockTypes();
                Memoize::$blockTypeGroupsByFieldId[$field->id] = $field->getGroups();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                
                throw $e;
            }
        }
        
        return $isValid;
    }
    
    /**
     * Deletes a Neo field.
     *
     * @param Field $field The field to delete.
     * @return bool Whether deletion was successful.
     * @throws \Throwable
     */
    public function delete(Field $field): bool
    {
        $dbService = Craft::$app->getDb();
        
        $transaction = $dbService->beginTransaction();
        try {
            $blockTypes = Neo::$plugin->blockTypes->getByFieldId($field->id);
            
            // sort block types so the sort order is descending
            // need to reverse to multi level blocks get deleted before the parent
            usort($blockTypes, function ($a, $b) {
                if ((int)$a->sortOrder === (int)$b->sortOrder) {
                    return 0;
                }
                
                return (int)$a->sortOrder > (int)$b->sortOrder ? -1 : 1;
            });
            
            foreach ($blockTypes as $blockType) {
                Neo::$plugin->blockTypes->delete($blockType);
            }
            
            Neo::$plugin->blockTypes->deleteGroupsByFieldId($field->id);
            
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            
            throw $e;
        }
        
        return true;
    }
    
    /**
     * Saves a Neo field's value for a given element.
     *
     * @param Field $field The Neo field.
     * @param ElementInterface $owner The element that owns the Neo field.
     * @param bool $isNew
     * @throws \Throwable
     */
    public function saveValue(Field $field, ElementInterface $owner)
    {
        $dbService = Craft::$app->getDb();
        $elementsService = Craft::$app->getElements();
        $neoSettings = Neo::$plugin->getSettings();
        
        $this->_rebuildIfDeleted = false;
        $query = $owner->getFieldValue($field->handle);
        
        if (($blocks = $query->getCachedResult()) !== null) {
            $saveAll = false;
        } else {
            $blocks = (clone $query)->anyStatus()->all();
            $saveAll = true;
        }
        
        $blockIds = [];
        $sortOrder = 0;
        $structureModified = false;
        
        $transaction = $dbService->beginTransaction();
        
        try {
            foreach ($blocks as $block) {
                $sortOrder++;
                if ($saveAll || !$block->id || $block->dirty) {
                    $block->ownerId = (int)$owner->id;
                    $block->sortOrder = $sortOrder;
                    $elementsService->saveElement($block, false);
                    
                    if (!$neoSettings->collapseAllBlocks) {
                        $block->cacheCollapsed();
                    }
                } elseif ((int)$block->sortOrder !== $sortOrder) {
                    // Just update its sortOrder
                    $block->sortOrder = $sortOrder;
                    $dbService->createCommand()->update('{{%neoblocks}}',
                        ['sortOrder' => $sortOrder],
                        ['id' => $block->id], [], false)
                        ->execute();
                    
                    $structureModified = true;
                }
                
                // check if block level has been changed
                if ((!$structureModified && $block->level !== (int)$block->oldLevel) || !$block->structureId || !$block->id) {
                    $structureModified = true;
                }
                
                $blockIds[] = $block->id;
            }
            
            $this->_deleteOtherBlocks($field, $owner, $blockIds);
            
            // need to check if the blocks is different e.g any deletions so we can rebuild the structure.
            if ($this->_rebuildIfDeleted) {
                $structureModified = true;
            }
            
            if ($structureModified) {
                $this->_saveNeoStructuresForSites($field, $owner, $blocks);
            }
            
            if (
                $field->propagationMethod !== Field::PROPAGATION_METHOD_ALL &&
                ($owner->propagateAll || !empty($owner->newSiteIds))
            ) {
                $ownerSiteIds = ArrayHelper::getColumn(ElementHelper::supportedSitesForElement($owner), 'siteId');
                $fieldSiteIds = $this->getSupportedSiteIds($field->propagationMethod, $owner);
                $otherSiteIds = array_diff($ownerSiteIds, $fieldSiteIds);
                
                if (!$owner->propagateAll) {
                    $otherSiteIds = array_intersect($otherSiteIds, $owner->newSiteIds);
                }
                
                if (!empty($otherSiteIds)) {
                    // Get the original element and duplicated element for each of those sites
                    /** @var Element[] $otherTargets */
                    $otherTargets = $owner::find()
                        ->drafts($owner->getIsDraft())
                        ->revisions($owner->getIsRevision())
                        ->id($owner->id)
                        ->siteId($otherSiteIds)
                        ->anyStatus()
                        ->all();
                    
                    // Duplicate neo blocks, ensuring we don't process the same blocks more than once
                    $handledSiteIds = [];
                    
                    $cachedQuery = clone $query;
                    $cachedQuery->anyStatus();
                    $cachedQuery->setCachedResult($blocks);
                    $owner->setFieldValue($field->handle, $cachedQuery);
                    
                    foreach ($otherTargets as $otherTarget) {
                        // Make sure we haven't already duplicated blocks for this site, via propagation from another site
                        if (isset($handledSiteIds[$otherTarget->siteId])) {
                            continue;
                        }
                        $this->duplicateBlocks($field, $owner, $otherTarget);
                        // Make sure we don't duplicate blocks for any of the sites that were just propagated to
                        $sourceSupportedSiteIds = $this->getSupportedSiteIds($field->propagationMethod, $otherTarget);
                        $handledSiteIds = array_merge($handledSiteIds, array_flip($sourceSupportedSiteIds));
                    }
                    $owner->setFieldValue($field->handle, $query);
                }
            }
            
            $transaction->commit();
            
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
    
    /**
     * Duplicates Neo blocks from one owner element to another.
     *
     * @param Field $field The Neo field to duplicate blocks for
     * @param ElementInterface $source The source element blocks should be duplicated from
     * @param ElementInterface $target The target element blocks should be duplicated to
     * @param bool $checkOtherSites Whether to duplicate blocks for the source element's other supported sites
     * @throws
     */
    public function duplicateBlocks(
        Field $field,
        ElementInterface $source,
        ElementInterface $target,
        bool $checkOtherSites = false
    ) {
        /** @var Element $source */
        /** @var Element $target */
        $elementsService = Craft::$app->getElements();
        /** @var BlockQuery $query */
        $query = $source->getFieldValue($field->handle);
        /** @var Block[] $blocks */
        if (($blocks = $query->getCachedResult()) === null) {
            $blocksQuery = clone $query;
            $blocks = $blocksQuery->anyStatus()->all();
        }
        $newBlockIds = [];
        
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $newBlocks = [];
            $newBlocksTaskData = [];
            
            foreach ($blocks as $block) {
                /** @var Block $newBlock */
                $collapsed = $block->getCollapsed();
                
                $newBlock = $elementsService->duplicateElement($block, [
                    'ownerId' => $target->id,
                    'owner' => $target,
                    'siteId' => $target->siteId,
                    'structureId' => null,
                    'propagating' => false,
                ]);

                // Levels not applying properly when saving drafts, so do it manually
                $newBlock->level = $block->level;
                
                $newBlock->setCollapsed($collapsed);
                $newBlock->cacheCollapsed();
                
                $newBlockIds[] = $newBlock->id;
                $newBlocksTaskData[] = [
                    'id' => $newBlock->id,
                    'sortOrder' => $newBlock->sortOrder,
                    'lft' => $newBlock->lft,
                    'rgt' => $newBlock->rgt,
                    'level' => $newBlock->level,
                ];
                $newBlocks[] = $newBlock;
            }
            // Delete any blocks that shouldn't be there anymore
            $this->_deleteOtherBlocks($field, $target, $newBlockIds);
            
            if ($this->_shouldCreateStructureWithJob($target)) {
                Craft::$app->queue->push(new DuplicateNeoStructureTask([
                    'field' => $field->id,
                    'owner' => [
                        'id' => $target->id,
                        'siteId' => $target->siteId
                    ],
                    'blocks' => $newBlocksTaskData,
                    'siteId' => null,
                    'supportedSites' => $this->getSupportedSiteIdsExCurrent($field, $target)
                ]));
            } else {
                $this->_saveNeoStructuresForSites($field, $target, $newBlocks);
            }
            
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        // Duplicate blocks for other sites as well?
        if ($checkOtherSites && $field->propagationMethod !== Field::PROPAGATION_METHOD_ALL) {
            // Find the target's site IDs that *aren't* supported by this site's neo blocks
            $targetSiteIds = ArrayHelper::getColumn(ElementHelper::supportedSitesForElement($target), 'siteId');
            $fieldSiteIds = $this->getSupportedSiteIds($field->propagationMethod, $target);
            $otherSiteIds = array_diff($targetSiteIds, $fieldSiteIds);
            
            if (!empty($otherSiteIds)) {
                // Get the original element and duplicated element for each of those sites
                /** @var Element[] $otherSources */
                $otherSources = $target::find()
                    ->drafts($source->getIsDraft())
                    ->revisions($source->getIsRevision())
                    ->id($source->id)
                    ->siteId($otherSiteIds)
                    ->anyStatus()
                    ->all();
                /** @var Element[] $otherTargets */
                $otherTargets = $target::find()
                    ->drafts($target->getIsDraft())
                    ->revisions($target->getIsRevision())
                    ->id($target->id)
                    ->siteId($otherSiteIds)
                    ->anyStatus()
                    ->indexBy('siteId')
                    ->all();
                
                // Duplicate neo blocks, ensuring we don't process the same blocks more than once
                $handledSiteIds = [];
                
                foreach ($otherSources as $otherSource) {
                    // Make sure the target actually exists for this site
                    if (!isset($otherTargets[$otherSource->siteId])) {
                        continue;
                    }
                    
                    // Make sure we haven't already duplicated blocks for this site, via propagation from another site
                    if (in_array($otherSource->siteId, $handledSiteIds, false)) {
                        continue;
                    }
                    
                    $this->duplicateBlocks($field, $otherSource, $otherTargets[$otherSource->siteId]);
                    
                    // Make sure we don't duplicate blocks for any of the sites that were just propagated to
                    $sourceSupportedSiteIds = $this->getSupportedSiteIds($field->propagationMethod, $otherSource);
                    $handledSiteIds = array_merge($handledSiteIds, $sourceSupportedSiteIds);
                }
            }
        }
    }
    
    /**
     * Returns the site IDs that are supported by neo blocks for the given neo field and owner element.
     *
     * @param Field $field
     * @param ElementInterface $owner
     * @return int[]
     * @throws \Throwable if reasons
     * @deprecated in 2.5.10. Use [[getSupportedSiteIds()]] instead.
     */
    public function getSupportedSiteIdsForField(Field $field, ElementInterface $owner): array
    {
        return $this->getSupportedSiteIds($field->propagationMethod, $owner);
    }
    
    /**
     * Returns the site IDs that are supported by neo blocks for the given propagation method and owner element.
     *
     * @param string $propagationMethod
     * @param ElementInterface $owner
     * @return int[]
     * @throws
     * @since 2.5.10
     */
    public function getSupportedSiteIds(string $propagationMethod, ElementInterface $owner): array
    {
        /** @var Element $owner */
        /** @var Site[] $allSites */
        $allSites = ArrayHelper::index(Craft::$app->getSites()->getAllSites(), 'id');
        $ownerSiteIds = ArrayHelper::getColumn(ElementHelper::supportedSitesForElement($owner), 'siteId');
        $siteIds = [];
        
        foreach ($ownerSiteIds as $siteId) {
            switch ($propagationMethod) {
                case Field::PROPAGATION_METHOD_NONE:
                    $include = $siteId == $owner->siteId;
                    break;
                case Field::PROPAGATION_METHOD_SITE_GROUP:
                    $include = $allSites[$siteId]->groupId == $allSites[$owner->siteId]->groupId;
                    break;
                case Field::PROPAGATION_METHOD_LANGUAGE:
                    $include = $allSites[$siteId]->language == $allSites[$owner->siteId]->language;
                    break;
                default:
                    $include = true;
                    break;
            }
            
            if ($include) {
                $siteIds[] = $siteId;
            }
        }
        
        return $siteIds;
    }
    
    public function getSupportedSiteIdsExCurrent($field, $owner)
    {
        // we need to setup the structure for the other supported sites too.
        // must be immediate to show changes on the front end.
        $supported = $this->getSupportedSiteIds($field->propagationMethod, $owner);
        
        // remove the current
        if (($key = array_search($owner->siteId, $supported)) !== false) {
            array_splice($supported, $key, 1);
        }
        
        return $supported;
    }
    
    // Private Methods
    // =========================================================================
    private function _shouldCreateStructureWithJob($target): bool
    {
        // if target is not a draft or revision
        $duplicate = $target->duplicateOf;
        
        // return true if creating a revision
        return $duplicate && $duplicate->draftId === null &&
            $duplicate->revisionId === null && $target->revisionId;
    }
    
    
    /**
     * Deletes blocks from an owner element
     *
     * @param Field $field The Neo field
     * @param ElementInterface The owner element
     * @param int[] $except Block IDs that should be left alone
     * @throws \Throwable if reasons
     */
    private function _deleteOtherBlocks(Field $field, ElementInterface $owner, array $except)
    {
        $supportedSites = $this->getSupportedSiteIds($field->propagationMethod, $owner);
        $supportedSitesCount = count($supportedSites);
        // throw new \Exception(print_r($supportedSitesCount, true));
        if ($supportedSitesCount > 1 && $field->propagationMethod !== Field::PROPAGATION_METHOD_NONE) {
            foreach ($supportedSites as $site) {
                $this->_deleteNeoBlocksAndStructures($field, $owner, $except, $site);
            }
        } else {
            $this->_deleteNeoBlocksAndStructures($field, $owner, $except);
        }
        // $this->_deleteNeoBlocksAndStructures($field, $owner, $except);
    }
    
    private function _checkSupportedSitesAndPropagation($field, $supportedSites)
    {
        // get the supported sites
        $supportedSitesCount = count($supportedSites);
        
        // if more than 1 supported sites and propagation method is not PROPAGATION_METHOD_NONE
        // then save the neo structures for each site.
        return $supportedSitesCount > 1 && $field->propagationMethod !== Field::PROPAGATION_METHOD_NONE;
    }
    
    private function _deleteNeoBlocksAndStructures(Field $field, ElementInterface $owner, $except, $sId = null)
    {
        $siteId = $sId ?? $owner->siteId;
        
        /** @var Element $owner */
        $deleteBlocks = Block::find()
            ->anyStatus()
            ->ownerId($owner->id)
            ->fieldId($field->id)
            ->siteId($siteId)
            ->inReverse()
            ->andWhere(['not', ['elements.id' => $except]])
            ->all();
        
        $elementsService = Craft::$app->getElements();
        
        foreach ($deleteBlocks as $deleteBlock) {
            $deleteBlock->forgetCollapsed();
            $elementsService->deleteElement($deleteBlock);
        }
        
        // if there are blocks to delete then we need to rebuild the structure.
        if (count($deleteBlocks) >= 1) {
            $this->_rebuildIfDeleted = true;
        }
    }
    
    private function _saveNeoStructuresForSites(Field $field, ElementInterface $owner, $blocks, $sId = null)
    {
        $siteId = $sId ?? $owner->siteId;
        
        // Delete any existing block structures associated with this field/owner/site combination
        while (($blockStructure = Neo::$plugin->blocks->getStructure($field->id, $owner->id, $siteId)) !== null) {
            Neo::$plugin->blocks->deleteStructure($blockStructure);
        }
        
        $blockStructure = new BlockStructure();
        $blockStructure->fieldId = (int)$field->id;
        $blockStructure->ownerId = (int)$owner->id;
        $blockStructure->ownerSiteId = (int)$siteId;
        
        Neo::$plugin->blocks->saveStructure($blockStructure);
        Neo::$plugin->blocks->buildStructure($blocks, $blockStructure);
        
        // if multi site then save the structure for it. since it's all the same then we can use the same structure.
        $supported = $this->getSupportedSiteIdsExCurrent($field, $owner);
        $supportedCount = count($supported);
        
        if ($supportedCount > 0) {
            // if has more than 3 sites then use a job instead to lighten the load.
            foreach ($supported as $s) {
                while (($mBlockStructure = Neo::$plugin->blocks->getStructure($field->id, $owner->id, $s)) !== null) {
                    Neo::$plugin->blocks->deleteStructure($mBlockStructure);
                }
                
                $multiBlockStructure = $blockStructure;
                $multiBlockStructure->id = null;
                $multiBlockStructure->ownerSiteId = $s;
                
                Neo::$plugin->blocks->saveStructure($multiBlockStructure);
            }
        }
    }
}
