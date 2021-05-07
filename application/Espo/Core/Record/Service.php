<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Record;

use Espo\Core\Exceptions\{
    Error,
    BadRequest,
    NotFound,
    Forbidden,
    NotFoundSilent,
    ForbiddenSilent,
    ConflictSilent,
};

use Espo\ORM\{
    Entity,
    Repository\Repository,
    Collection,
    EntityManager,
};

use Espo\Entities\User;

use Espo\Services\Stream as StreamService;

use Espo\Core\{
    Acl,
    Acl\Table as AclTable,
    Select\SearchParams,
    Select\SelectBuilderFactory,
    Record\Crud,
    Record\Collection as RecordCollection,
    FieldValidation\Params as FieldValidationParams,
    FieldProcessing\ReadLoadProcessor,
    FieldProcessing\ListLoadProcessor,
    FieldProcessing\LoaderParams as FieldLoaderParams,
};

use Espo\Tools\{
    Export\Export as ExportTool,
};

use Espo\Core\Di;

use StdClass;
use RuntimeException;

/**
 * The layer between a controller and ORM repository. For CRUD and other operations with records.
 * Access control is processed here.
 */
class Service implements Crud,

    Di\ConfigAware,
    Di\ServiceFactoryAware,
    Di\EntityManagerAware,
    Di\UserAware,
    Di\MetadataAware,
    Di\AclAware,
    Di\InjectableFactoryAware,
    Di\FieldUtilAware,
    Di\FieldValidationManagerAware,
    Di\RecordServiceContainerAware,
    Di\SelectBuilderFactoryAware
{
    use Di\ConfigSetter;
    use Di\ServiceFactorySetter;
    use Di\EntityManagerSetter;
    use Di\UserSetter;
    use Di\MetadataSetter;
    use Di\AclSetter;
    use Di\InjectableFactorySetter;
    use Di\FieldUtilSetter;
    use Di\FieldValidationManagerSetter;
    use Di\RecordServiceContainerSetter;
    use Di\SelectBuilderFactorySetter;

    protected $getEntityBeforeUpdate = false;

    protected $entityType = null;

    private $streamService = null;

    protected $notFilteringAttributeList = []; // TODO maybe remove it

    protected $forbiddenAttributeList = [];

    protected $internalAttributeList = [];

    protected $onlyAdminAttributeList = [];

    protected $readOnlyAttributeList = [];

    protected $nonAdminReadOnlyAttributeList = [];

    protected $forbiddenLinkList = [];

    protected $internalLinkList = [];

    protected $readOnlyLinkList = [];

    protected $nonAdminReadOnlyLinkList = [];

    protected $onlyAdminLinkList = [];

    protected $linkParams = [];

    protected $linkMandatorySelectAttributeList = [];

    protected $noEditAccessRequiredLinkList = [];

    protected $noEditAccessRequiredForLink = false;

    protected $checkForDuplicatesInUpdate = false;

    protected $actionHistoryDisabled = false;

    protected $duplicatingLinkList = [];

    protected $listCountQueryDisabled = false;

    protected $maxSelectTextAttributeLength = null;

    protected $maxSelectTextAttributeLengthDisabled = false;

    protected $selectAttributeList = null;

    protected $mandatorySelectAttributeList = [];

    protected $forceSelectAllAttributes = false;

    protected $validateSkipFieldList = [];

    /**
     * @todo Move to metadata.
     */
    protected $validateRequiredSkipFieldList = [];

    protected $findDuplicatesSelectAttributeList = ['id', 'name'];

    protected $duplicateIgnoreFieldList = [];

    protected $duplicateIgnoreAttributeList = [];

    /**
     * @var Acl
     */
    protected $acl = null;

    /**
     * @var User
     */
    protected $user = null;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var SelectBuilderFactory
     */
    protected $selectBuilderFactory;

    private $listLoadProcessor;

    protected const MAX_SELECT_TEXT_ATTRIBUTE_LENGTH = 5000;

    protected const FIND_DUPLICATES_LIMIT = 10;

    public function __construct()
    {

    }

    public function setEntityType(string $entityType): void
    {
        if ($this->entityType && $this->entityType !== $entityType) {
            throw new RuntimeException("entityType is already set.");
        }

        if ($this->entityType) {
            return;
        }

        $this->entityType = $entityType;
    }

    protected function getRepository(): Repository
    {
        return $this->entityManager->getRepository($this->entityType);
    }

    public function processActionHistoryRecord(string $action, Entity $entity): void
    {
        if ($this->actionHistoryDisabled) {
            return;
        }

        if ($this->config->get('actionHistoryDisabled')) {
            return;
        }

        $historyRecord = $this->entityManager->getEntity('ActionHistoryRecord');

        $historyRecord->set('action', $action);
        $historyRecord->set('userId', $this->user->id);
        $historyRecord->set('authTokenId', $this->user->get('authTokenId'));
        $historyRecord->set('ipAddress', $this->user->get('ipAddress'));
        $historyRecord->set('authLogRecordId', $this->user->get('authLogRecordId'));

        if ($entity) {
            $historyRecord->set([
                'targetType' => $entity->getEntityType(),
                'targetId' => $entity->id
            ]);
        }

        $this->entityManager->saveEntity($historyRecord);
    }

    /**
     * Read a record by ID. Access control check is performed.
     *
     * @throws Error
     * @throws NotFoundSilent If no read access.
     */
    public function read(string $id): Entity
    {
        if (!$this->acl->check($this->entityType, AclTable::ACTION_READ)) {
            throw new ForbiddenSilent();
        }

        if (empty($id)) {
            throw new Error("No ID passed.");
        }

        $entity = $this->getEntity($id);

        if (!$entity) {
            throw new NotFoundSilent("Record {$id} does not exist.");
        }

        $this->processActionHistoryRecord('read', $entity);

        return $entity;
    }

    /**
     * Get an entity by ID. Access control check is performed.
     * If ID is not specified then it will return an empty entity.
     *
     * @throws ForbiddenSilent If no read access.
     */
    public function getEntity(?string $id = null): ?Entity
    {
        if ($id === null) {
            return $this->getRepository()->getNew();
        }

        $entity = $this->getRepository()->getById($id);

        if (!$entity && $this->user->isAdmin()) {
            $entity = $this->getEntityEvenDeleted($id);
        }

        if (!$entity) {
            return null;
        }

        $this->loadAdditionalFields($entity);

        if (!$this->acl->check($entity, AclTable::ACTION_READ)) {
            throw new ForbiddenSilent("No 'read' access.");
        }

        $this->prepareEntityForOutput($entity);

        return $entity;
    }

    protected function getStreamService(): StreamService
    {
        if (empty($this->streamService)) {
            $this->streamService = $this->serviceFactory->create('Stream');
        }

        return $this->streamService;
    }

    private function createReadLoadProcessor(): ReadLoadProcessor
    {
        return $this->injectableFactory->create(ReadLoadProcessor::class);
    }

    private function getListLoadProcessor(): ListLoadProcessor
    {
        if (!$this->listLoadProcessor) {
            $this->listLoadProcessor = $this->injectableFactory->create(ListLoadProcessor::class);
        }

        return $this->listLoadProcessor;
    }

    public function loadAdditionalFields(Entity $entity)
    {
        $loadProcessor = $this->createReadLoadProcessor();

        $loadProcessor->process($entity);
    }

    private function loadListAdditionalFields(Entity $entity, ?SearchParams $searchParams): void
    {
        $params = new FieldLoaderParams();

        if ($searchParams && $searchParams->getSelect()) {
            $params = $params->withSelect($searchParams->getSelect());
        }

        $loadProcessor = $this->getListLoadProcessor();

        $loadProcessor->process($entity, $params);
    }

    public function loadAdditionalFieldsForExport(Entity $entity)
    {
    }

    /**
     * @return void
     * @throws BadRequest
     */
    public function processValidation(Entity $entity, $data)
    {
        $params = FieldValidationParams
            ::fromNothing()
            ->withSkipFieldList($this->validateSkipFieldList)
            ->withTypeSkipFieldList('required', $this->validateRequiredSkipFieldList);

        $this->fieldValidationManager->process($entity, $data, $params);
    }

    /**
     * @return void
     * @throws Forbidden
     */
    public function processAssignmentCheck(Entity $entity)
    {
        if (!$this->checkAssignment($entity)) {
            throw new Forbidden("Assignment failure: assigned user or team not allowed.");
        }
    }

    public function checkAssignment(Entity $entity): bool
    {
        if (!$this->isPermittedAssignedUser($entity)) {
            return false;
        }

        if (!$this->isPermittedTeams($entity)) {
            return false;
        }

        if ($entity->hasLinkMultipleField('assignedUsers')) {
            if (!$this->isPermittedAssignedUsers($entity)) {
                return false;
            }
        }

        return true;
    }

    public function isPermittedAssignedUsers(Entity $entity): bool
    {
        if (!$entity->hasLinkMultipleField('assignedUsers')) {
            return true;
        }

        if ($this->user->isPortal()) {
            if (count($entity->getLinkMultipleIdList('assignedUsers')) === 0) {
                return true;
            }
        }

        $assignmentPermission = $this->acl->get('assignmentPermission');

        if (
            $assignmentPermission === true ||
            $assignmentPermission === AclTable::LEVEL_YES ||
            !in_array($assignmentPermission, [AclTable::LEVEL_TEAM, AclTable::LEVEL_NO])
        ) {
            return true;
        }

        $toProcess = false;

        if (!$entity->isNew()) {
            $userIdList = $entity->getLinkMultipleIdList('assignedUsers');

            if ($entity->isAttributeChanged('assignedUsersIds')) {
                $toProcess = true;
            }
        }
        else {
            $toProcess = true;
        }

        $userIdList = $entity->getLinkMultipleIdList('assignedUsers');

        if ($toProcess) {
            if (empty($userIdList)) {
                if ($assignmentPermission == AclTable::LEVEL_NO && !$this->user->isApi()) {
                    return false;
                }

                return true;
            }

            $fetchedAssignedUserIdList = $entity->getFetched('assignedUsersIds');

            if ($assignmentPermission === AclTable::LEVEL_NO) {
                foreach ($userIdList as $userId) {
                    if (!$entity->isNew() && in_array($userId, $fetchedAssignedUserIdList)) {
                        continue;
                    }

                    if ($this->user->id != $userId) {
                        return false;
                    }
                }
            } else if ($assignmentPermission === AclTable::LEVEL_TEAM) {
                $teamIdList = $this->user->getLinkMultipleIdList('teams');

                foreach ($userIdList as $userId) {
                    if (!$entity->isNew() && in_array($userId, $fetchedAssignedUserIdList)) {
                        continue;
                    }

                    if (
                        !$this->entityManager
                            ->getRepository('User')
                            ->checkBelongsToAnyOfTeams($userId, $teamIdList)
                    ) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    public function isPermittedAssignedUser(Entity $entity): bool
    {
        if (!$entity->hasAttribute('assignedUserId')) {
            return true;
        }

        $assignedUserId = $entity->get('assignedUserId');

        if ($this->user->isPortal()) {
            if (!$entity->isAttributeChanged('assignedUserId') && empty($assignedUserId)) {
                return true;
            }
        }

        $assignmentPermission = $this->acl->get('assignmentPermission');

        if (
            $assignmentPermission === true ||
            $assignmentPermission === AclTable::LEVEL_YES ||
            !in_array($assignmentPermission, [AclTable::LEVEL_TEAM, AclTable::LEVEL_NO])
        ) {
            return true;
        }

        $toProcess = false;

        if (!$entity->isNew()) {
            if ($entity->isAttributeChanged('assignedUserId')) {
                $toProcess = true;
            }
        } else {
            $toProcess = true;
        }

        if ($toProcess) {
            if (empty($assignedUserId)) {
                if ($assignmentPermission === AclTable::LEVEL_NO && !$this->user->isApi()) {
                    return false;
                }

                return true;
            }

            if ($assignmentPermission === AclTable::LEVEL_NO) {
                if ($this->user->id !== $assignedUserId) {
                    return false;
                }
            }
            else if ($assignmentPermission === AclTable::LEVEL_TEAM) {
                $teamIdList = $this->user->get('teamsIds');

                if (
                    !$this->entityManager
                        ->getRepository('User')
                        ->checkBelongsToAnyOfTeams($assignedUserId, $teamIdList)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    public function isPermittedTeams(Entity $entity): bool
    {
        $assignmentPermission = $this->acl->get('assignmentPermission');

        if (
            empty($assignmentPermission) ||
            $assignmentPermission === true ||
            !in_array($assignmentPermission, [AclTable::LEVEL_TEAM, AclTable::LEVEL_NO])
        ) {
            return true;
        }

        if (!$entity->hasLinkMultipleField('teams')) {
            return true;
        }

        $teamIdList = $entity->getLinkMultipleIdList('teams');

        if (empty($teamIdList)) {
            if ($assignmentPermission === 'team') {
                if ($entity->hasLinkMultipleField('assignedUsers')) {
                    $assignedUserIdList = $entity->getLinkMultipleIdList('assignedUsers');

                    if (empty($assignedUserIdList)) {
                        return false;
                    }
                }
                else if ($entity->hasAttribute('assignedUserId')) {
                    if (!$entity->get('assignedUserId')) {
                        return false;
                    }
                }
            }

            return true;
        }

        $newIdList = [];

        if (!$entity->isNew()) {
            $existingIdList = [];

            $teamCollection = $this->entityManager
                ->getRepository($entity->getEntityType())
                ->getRelation($entity, 'teams')
                ->select('id')
                ->find();

            foreach ($teamCollection as $team) {
                $existingIdList[] = $team->id;
            }

            foreach ($teamIdList as $id) {
                if (!in_array($id, $existingIdList)) {
                    $newIdList[] = $id;
                }
            }
        } else {
            $newIdList = $teamIdList;
        }

        if (empty($newIdList)) {
            return true;
        }

        $userTeamIdList = $this->user->getLinkMultipleIdList('teams');

        foreach ($newIdList as $id) {
            if (!in_array($id, $userTeamIdList)) {
                return false;
            }
        }

        return true;
    }

    protected function filterInputAttribute($attribute, $value)
    {
        if (in_array($attribute, $this->notFilteringAttributeList)) {
            return $value;
        }

        $methodName = 'filterInputAttribute' . ucfirst($attribute);

        if (method_exists($this, $methodName)) {
            $value = $this->$methodName($value);
        }

        return $value;
    }

    protected function filterInput($data)
    {
        foreach ($this->readOnlyAttributeList as $attribute) {
            unset($data->$attribute);
        }

        foreach ($this->forbiddenAttributeList as $attribute) {
            unset($data->$attribute);
        }

        foreach ($data as $key => $value) {
            $data->$key = $this->filterInputAttribute($key, $data->$key);
        }

        if (!$this->user->isAdmin()) {
            foreach ($this->onlyAdminAttributeList as $attribute) {
                unset($data->$attribute);
            }
        }

        $forbiddenAttributeList = $this->acl
            ->getScopeForbiddenAttributeList($this->entityType, AclTable::ACTION_EDIT);

        foreach ($forbiddenAttributeList as $attribute) {
            unset($data->$attribute);
        }

        if (!$this->user->isAdmin()) {
            foreach ($this->nonAdminReadOnlyAttributeList as $attribute) {
                unset($data->$attribute);
            }
        }
    }

    public function filterCreateInput(StdClass $data): void
    {
        unset($data->deleted);
        unset($data->id);
        unset($data->modifiedById);
        unset($data->modifiedByName);
        unset($data->modifiedAt);
        unset($data->createdById);
        unset($data->createdByName);
        unset($data->createdAt);

        $this->filterInput($data);

        $this->handleInput($data);
        $this->handleCreateInput($data);
    }

    public function filterUpdateInput(StdClass $data): void
    {
        unset($data->deleted);
        unset($data->id);
        unset($data->modifiedById);
        unset($data->modifiedByName);
        unset($data->modifiedAt);
        unset($data->createdById);
        unset($data->createdByName);
        unset($data->createdAt);

        $this->filterInput($data);

        $this->handleInput($data);
    }

    /**
     * @deprecated
     */
    protected function handleCreateInput($data)
    {
    }

    /**
     * @deprecated
     */
    protected function handleInput($data)
    {
    }

    protected function processDuplicateCheck(Entity $entity, $data)
    {
        if (
            !empty($data->_skipDuplicateCheck) ||
            !empty($data->skipDuplicateCheck) ||
            !empty($data->forceDuplicate)
        ) {
            return;
        }

        $duplicateList = $this->findDuplicates($entity, $data);

        if (empty($duplicateList)) {
            return;
        }

        $list = [];

        foreach ($duplicateList as $e) {
            $list[] = $e->getValueMap();
        }

        throw ConflictSilent::createWithBody('duplicate', json_encode($list));
    }

    public function populateDefaults(Entity $entity, StdClass $data): void
    {
        if (!$this->user->isPortal()) {
            $forbiddenFieldList = null;

            if ($entity->hasAttribute('assignedUserId')) {
                $forbiddenFieldList = $this->acl
                    ->getScopeForbiddenFieldList($this->entityType, AclTable::ACTION_EDIT);

                if (in_array('assignedUser', $forbiddenFieldList)) {
                    $entity->set('assignedUserId', $this->user->id);
                    $entity->set('assignedUserName', $this->user->get('name'));
                }
            }

            if ($entity->hasLinkMultipleField('teams')) {
                if (is_null($forbiddenFieldList)) {
                    $forbiddenFieldList = $this->acl
                        ->getScopeForbiddenFieldList($this->entityType, AclTable::ACTION_EDIT);
                }

                if (
                    in_array('teams', $forbiddenFieldList) &&
                    $this->user->get('defaultTeamId')
                ) {

                    $defaultTeamId = $this->user->get('defaultTeamId');

                    $entity->addLinkMultipleId('teams', $defaultTeamId);

                    $teamsNames = $entity->get('teamsNames');

                    if (!$teamsNames || !is_object($teamsNames)) {
                        $teamsNames = (object) [];
                    }

                    $teamsNames->$defaultTeamId = $this->user->get('defaultTeamName');

                    $entity->set('teamsNames', $teamsNames);

                }
            }
        }

        foreach ($this->fieldUtil->getEntityTypeFieldList($this->entityType) as $field) {
            $type = $this->fieldUtil->getEntityTypeFieldParam($this->entityType, $field, 'type');

            if ($type === 'currency') {
                if ($entity->get($field) && !$entity->get($field . 'Currency')) {
                    $entity->set($field . 'Currency', $this->config->get('defaultCurrency'));
                }
            }
        }
    }

    /**
     * Create a record.
     *
     * @throws ForbiddenSilent If no create access.
     */
    public function create(StdClass $data): Entity
    {
        if (!$this->acl->check($this->entityType, AclTable::ACTION_CREATE)) {
            throw new ForbiddenSilent();
        }

        $entity = $this->getRepository()->get();

        $this->filterCreateInput($data);

        $entity->set($data);

        $this->populateDefaults($entity, $data);

        if (!$this->acl->check($entity, AclTable::ACTION_CREATE)) {
            throw new ForbiddenSilent("No create access.");
        }

        $this->processValidation($entity, $data);
        $this->processAssignmentCheck($entity);
        $this->processDuplicateCheck($entity, $data);
        $this->beforeCreateEntity($entity, $data);

        $this->entityManager->saveEntity($entity);

        $this->afterCreateEntity($entity, $data);
        $this->afterCreateProcessDuplicating($entity, $data);
        $this->loadAdditionalFields($entity);
        $this->prepareEntityForOutput($entity);
        $this->processActionHistoryRecord('create', $entity);

        return $entity;
    }

    /**
     * Update a record.
     *
     * @throws BadRequest
     * @throws NotFound If record not found.
     * @throws Forbidden If no access.
     */
    public function update(string $id, StdClass $data): Entity
    {
        if (!$this->acl->check($this->entityType, AclTable::ACTION_EDIT)) {
            throw new ForbiddenSilent();
        }

        if (empty($id)) {
            throw new BadRequest("ID is empty.");
        }

        $this->filterUpdateInput($data);

        if ($this->getEntityBeforeUpdate) {
            $entity = $this->getEntity($id);
        } else {
            $entity = $this->getRepository()->get($id);
        }

        if (!$entity) {
            throw new NotFound("Record {$id} not found.");
        }

        if (!$this->acl->check($entity, AclTable::ACTION_EDIT)) {
            throw new ForbiddenSilent("No edit access.");
        }

        $entity->set($data);

        $this->processValidation($entity, $data);

        $this->processAssignmentCheck($entity);

        $this->beforeUpdateEntity($entity, $data);

        if ($this->checkForDuplicatesInUpdate) {
            $this->processDuplicateCheck($entity, $data);
        }

        $this->entityManager->saveEntity($entity);

        $this->afterUpdateEntity($entity, $data);

        $this->prepareEntityForOutput($entity);

        $this->processActionHistoryRecord('update', $entity);

        return $entity;
    }

    /**
     * Delete a record.
     *
     * @throws Forbidden
     * @throws BadRequest
     * @throws NotFound
     */
    public function delete(string $id): void
    {
        if (!$this->acl->check($this->entityType, AclTable::ACTION_DELETE)) {
            throw new ForbiddenSilent();
        }

        if (empty($id)) {
            throw new BadRequest("ID is empty.");
        }

        $entity = $this->getRepository()->get($id);

        if (!$entity) {
            throw new NotFound("Record {$id} not found.");
        }

        if (!$this->acl->check($entity, AclTable::ACTION_DELETE)) {
            throw new ForbiddenSilent("No delete access.");
        }

        $this->beforeDeleteEntity($entity);

        $this->getRepository()->remove($entity);

        $this->afterDeleteEntity($entity);

        $this->processActionHistoryRecord('delete', $entity);
    }

    /**
     * Find records.
     *
     * @throws Forbidden
     */
    public function find(SearchParams $searchParams): RecordCollection
    {
        if (!$this->acl->check($this->entityType, AclTable::ACTION_READ)) {
            throw new ForbiddenSilent();
        }

        $disableCount =
            $this->listCountQueryDisabled ||
            $this->metadata->get(['entityDefs', $this->entityType, 'collection', 'countDisabled']);

        $maxSize = $searchParams->getMaxSize();

        if ($disableCount && $maxSize) {
            $searchParams = $searchParams->withMaxSize($maxSize + 1);
        }

        $preparedSearchParams = $this->prepareSearchParams($searchParams);

        $selectBuilder = $this->selectBuilderFactory->create();

        $query = $selectBuilder
            ->from($this->entityType)
            ->withStrictAccessControl()
            ->withSearchParams($preparedSearchParams)
            ->build();

        $collection = $this->getRepository()
            ->clone($query)
            ->find();

        foreach ($collection as $entity) {
            $this->loadListAdditionalFields($entity, $preparedSearchParams);

            $this->prepareEntityForOutput($entity);
        }

        if (!$disableCount) {
            $total = $this->getRepository()
                ->clone($query)
                ->count();
        }
        else if ($maxSize && count($collection) > $maxSize) {
            $total = RecordCollection::TOTAL_HAS_MORE;

            unset($collection[count($collection) - 1]);
        }
        else {
            $total = RecordCollection::TOTAL_HAS_NO_MORE;
        }

        return new RecordCollection($collection, $total);
    }

    protected function getEntityEvenDeleted(string $id): ?Entity
    {
        $query = $this->entityManager->getQueryBuilder()
            ->select()
            ->from($this->entityType)
            ->where([
                'id' => $id,
            ])
            ->withDeleted()
            ->build();

        return $this->getRepository()->clone($query)->findOne();
    }

    /**
     * Restore a deleted record.
     *
     * @throws NotFound If not found.
     * @throws Forbidden If no access.
     */
    public function restoreDeleted(string $id): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden();
        }

        $entity = $this->getEntityEvenDeleted($id);

        if (!$entity) {
            throw new NotFound();
        }

        if (!$entity->get('deleted')) {
            throw new Forbidden();
        }

        $this->getRepository()->restoreDeleted($entity->id);
    }

    public function getMaxSelectTextAttributeLength(): ?int
    {
        if ($this->maxSelectTextAttributeLengthDisabled) {
            return null;
        }

        if ($this->maxSelectTextAttributeLength) {
            return $this->maxSelectTextAttributeLength;
        }

        return $this->config->get('maxSelectTextAttributeLengthForList') ??
            self::MAX_SELECT_TEXT_ATTRIBUTE_LENGTH;
    }

    /**
     * Find linked records.
     *
     * @throws NotFound If a record not found.
     * @throws Forbidden If no access.
     * @throws Error
     */
    public function findLinked(string $id, string $link, SearchParams $searchParams): RecordCollection
    {
        if (!$this->acl->check($this->entityType, AclTable::ACTION_READ)) {
            throw new ForbiddenSilent("No access.");
        }

        $entity = $this->getRepository()->get($id);

        if (!$entity) {
            throw new NotFound();
        }

        if (!$this->acl->check($entity, AclTable::ACTION_READ)) {
            throw new ForbiddenSilent();
        }

        if (!$link) {
            throw new Error("Empty link.");
        }

        $this->processForbiddenLinkReadCheck($link);

        $methodName = 'findLinked' . ucfirst($link);

        if (method_exists($this, $methodName)) {
            return $this->$methodName($id, $searchParams);
        }

        $foreignEntityType = $this->entityManager
            ->getDefs()
            ->getEntity($this->entityType)
            ->getRelation($link)
            ->getForeignEntityType();

        $linkParams = $this->linkParams[$link] ?? [];

        $skipAcl = $linkParams['skipAcl'] ?? false;

        if (!$skipAcl) {
            if (!$this->acl->check($foreignEntityType, AclTable::ACTION_READ)) {
                throw new Forbidden();
            }
        }

        $recordService = $this->recordServiceContainer->get($foreignEntityType);

        $disableCountPropertyName = 'findLinked' . ucfirst($link) . 'CountQueryDisabled';

        $disableCount =
            property_exists($this, $disableCountPropertyName) &&
            $this->$disableCountPropertyName;

        $maxSize = $searchParams->getMaxSize();

        if ($disableCount && $maxSize) {
            $searchParams = $searchParams->withMaxSize($maxSize + 1);
        }

        $preparedSearchParams = $this->prepareLinkSearchParams(
            $recordService->prepareSearchParams($searchParams),
            $link
        );

        $selectBuilder = $this->selectBuilderFactory->create();

        $selectBuilder
            ->from($foreignEntityType)
            ->withSearchParams($preparedSearchParams);

        if (!$skipAcl) {
            $selectBuilder->withStrictAccessControl();
        }
        else {
            $selectBuilder->withComplexExpressionsForbidden();
            $selectBuilder->withWherePermissionCheck();
        }

        $query = $selectBuilder->build();

        $collection = $this->entityManager
            ->getRepository($this->entityType)
            ->getRelation($entity, $link)
            ->clone($query)
            ->find();

        foreach ($collection as $itemEntity) {
            $this->loadListAdditionalFields($itemEntity, $preparedSearchParams);

            $recordService->prepareEntityForOutput($itemEntity);
        }

        if (!$disableCount) {
            $total = $this->entityManager
                ->getRepository($this->entityType)
                ->getRelation($entity, $link)
                ->clone($query)
                ->count();
        }
        else if ($maxSize && count($collection) > $maxSize) {
            $total = RecordCollection::TOTAL_HAS_MORE;

            unset($collection[count($collection) - 1]);
        }
        else {
            $total = RecordCollection::TOTAL_HAS_NO_MORE;
        }

        return new RecordCollection($collection, $total);
    }

    /**
     * Link records.
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function link(string $id, string $link, string $foreignId): void
    {
        if (!$this->acl->check($this->entityType)) {
            throw new Forbidden();
        }

        if (empty($id) || empty($link) || empty($foreignId)) {
            throw new BadRequest;
        }

        $this->processForbiddenLinkEditCheck($link);

        $entity = $this->getRepository()->get($id);

        if (!$entity) {
            throw new NotFound();
        }

        if ($this->noEditAccessRequiredForLink) {
            if (!$this->acl->check($entity, AclTable::ACTION_READ)) {
                throw new Forbidden();
            }
        }
        else if (!$this->acl->check($entity, AclTable::ACTION_EDIT)) {
            throw new Forbidden();
        }

        $methodName = 'link' . ucfirst($link);

        if ($link !== 'entity' && $link !== 'entityMass' && method_exists($this, $methodName)) {
            $this->$methodName($id, $foreignId);

            return;
        }

        $foreignEntityType = $entity->getRelationParam($link, 'entity');

        if (!$foreignEntityType) {
            throw new Error("Entity '{$this->entityType}' has not relation '{$link}'.");
        }

        $foreignEntity = $this->entityManager->getEntity($foreignEntityType, $foreignId);

        if (!$foreignEntity) {
            throw new NotFound();
        }

        $accessActionRequired = AclTable::ACTION_EDIT;

        if (in_array($link, $this->noEditAccessRequiredLinkList)) {
            $accessActionRequired = AclTable::ACTION_READ;
        }

        if (!$this->acl->check($foreignEntity, $accessActionRequired)) {
            throw new Forbidden();
        }

        $this->getRepository()->relate($entity, $link, $foreignEntity);
    }

    /**
     * Unlink records.
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function unlink(string $id, string $link, string $foreignId): void
    {
        if (!$this->acl->check($this->entityType)) {
            throw new Forbidden();
        }

        if (empty($id) || empty($link) || empty($foreignId)) {
            throw new BadRequest;
        }

        $this->processForbiddenLinkEditCheck($link);

        $entity = $this->getRepository()->get($id);

        if (!$entity) {
            throw new NotFound();
        }

        if ($this->noEditAccessRequiredForLink) {
            if (!$this->acl->check($entity, AclTable::ACTION_READ)) {
                throw new Forbidden();
            }
        }
        else if (!$this->acl->check($entity, AclTable::ACTION_EDIT)) {
            throw new Forbidden();
        }

        $methodName = 'unlink' . ucfirst($link);

        if ($link !== 'entity' && method_exists($this, $methodName)) {
            $this->$methodName($id, $foreignId);

            return;
        }

        $foreignEntityType = $entity->getRelationParam($link, 'entity');

        if (!$foreignEntityType) {
            throw new Error("Entity '{$this->entityType}' has not relation '{$link}'.");
        }

        $foreignEntity = $this->entityManager->getEntity($foreignEntityType, $foreignId);

        if (!$foreignEntity) {
            throw new NotFound();
        }

        $accessActionRequired = AclTable::ACTION_EDIT;

        if (in_array($link, $this->noEditAccessRequiredLinkList)) {
            $accessActionRequired = AclTable::ACTION_READ;
        }

        if (!$this->acl->check($foreignEntity, $accessActionRequired)) {
            throw new Forbidden();
        }

        $this->getRepository()->unrelate($entity, $link, $foreignEntity);
    }

    public function linkFollowers(string $id, string $foreignId): void
    {
        if (!$this->acl->check($this->entityType, AclTable::ACTION_EDIT)) {
            throw new Forbidden();
        }

        if (!$this->metadata->get(['scopes', $this->entityType, 'stream'])) {
            throw new NotFound();
        }

        $entity = $this->getRepository()->get($id);

        if (!$entity) {
            throw new NotFound();
        }

        $user = $this->entityManager->getEntity('User', $foreignId);

        if (!$user) {
            throw new NotFound();
        }

        if (!$this->acl->check($entity, AclTable::ACTION_EDIT)) {
            throw new ForbiddenSilent("No 'edit' access.");
        }

        if (!$this->acl->check($entity, AclTable::ACTION_STREAM)) {
            throw new ForbiddenSilent("No 'stream' access.");
        }

        if (!$user->isPortal() && !$this->acl->check($user, AclTable::ACTION_READ)) {
            throw new ForbiddenSilent("No 'read' access to user.");
        }

        if ($user->isPortal() && $this->acl->get('portal') !== AclTable::LEVEL_YES) {
            throw new ForbiddenSilent("No 'portal' permission.");
        }

        if (
            !$user->isPortal() &&
            $this->user->getId() !== $user->getId() &&
            !$this->acl->checkUserPermission($user, 'followerManagement')
        ) {
            throw new Forbidden();
        }

        $result = $this->getStreamService()->followEntity($entity, $foreignId);

        if (!$result) {
            throw new Forbidden("Could not add a user to followers. The user needs to have 'stream' access.");
        }
    }

    public function unlinkFollowers(string $id, string $foreignId): void
    {
        if (!$this->acl->check($this->entityType, AclTable::ACTION_EDIT)) {
            throw new Forbidden();
        }

        if (!$this->metadata->get(['scopes', $this->entityType, 'stream'])) {
            throw new NotFound();
        }

        $entity = $this->getRepository()->get($id);

        if (!$entity) {
            throw new NotFound();
        }

        $user = $this->entityManager->getEntity('User', $foreignId);

        if (!$user) {
            throw new NotFound();
        }

        if (!$this->acl->check($entity, AclTable::ACTION_EDIT)) {
            throw new ForbiddenSilent("No 'edit' access.");
        }

        if (!$this->acl->check($entity, AclTable::ACTION_STREAM)) {
            throw new ForbiddenSilent("No 'stream' access.");
        }

        if (!$user->isPortal() && !$this->acl->check($user, AclTable::ACTION_READ)) {
            throw new ForbiddenSilent("No 'read' access to user.");
        }

        if ($user->isPortal() && $this->acl->get('portal') !== AclTable::LEVEL_YES) {
            throw new ForbiddenSilent("No 'portal' permission.");
        }

        if (
            !$user->isPortal() &&
            $this->user->getId() !== $user->getId() &&
            !$this->acl->checkUserPermission($user, 'followerManagement')
        ) {
            throw new Forbidden();
        }

        $this->getStreamService()->unfollowEntity($entity, $foreignId);
    }

    public function massLink(string $id, string $link, array $where, ?array $selectData = null)
    {
        if (!$this->acl->check($this->entityType, AclTable::ACTION_EDIT)) {
            throw new Forbidden();
        }

        if (empty($id) || empty($link)) {
            throw new BadRequest;
        }

        $this->processForbiddenLinkEditCheck($link);

        $entity = $this->getRepository()->get($id);

        if (!$entity) {
            throw new NotFound();
        }

        if (!$this->acl->check($entity, AclTable::ACTION_EDIT)) {
            throw new Forbidden();
        }

        $methodName = 'massLink' . ucfirst($link);

        if (method_exists($this, $methodName)) {
            return $this->$methodName($id, $where, $selectData);
        }

        $foreignEntityType = $entity->getRelationParam($link, 'entity');

        if (empty($foreignEntityType)) {
            throw new Error();
        }

        $accessActionRequired = AclTable::ACTION_EDIT;

        if (in_array($link, $this->noEditAccessRequiredLinkList)) {
            $accessActionRequired = AclTable::ACTION_READ;
        }

        if (!$this->acl->check($foreignEntityType, $accessActionRequired)) {
            throw new Forbidden();
        }

        if (!is_array($where)) {
            $where = [];
        }
        $params['where'] = $where;

        if (is_array($selectData)) {
            foreach ($selectData as $k => $v) {
                $params[$k] = $v;
            }
        }

        $query = $this->selectBuilderFactory->create()
            ->from($foreignEntityType)
            ->withStrictAccessControl()
            ->withSearchParams(SearchParams::fromRaw($params))
            ->build();

        if ($this->acl->getLevel($foreignEntityType, $accessActionRequired) === AclTable::LEVEL_ALL) {
            $this->getRepository()
                ->getRelation($entity, $link)
                ->massRelate($query);

            return true;
        }

        $countRelated = 0;

        $foreignCollection = $this->entityManager
            ->getRepository($foreignEntityType)
            ->clone($query)
            ->find();

        foreach ($foreignCollection as $foreignEntity) {
            if (!$this->acl->check($foreignEntity, $accessActionRequired)) {
                continue;
            }

            $this->getRepository()
                ->getRelation($entity, $link)
                ->relate($foreignEntity);

            $countRelated++;
        }

        if ($countRelated) {
            return true;
        }

        return false;
    }

    protected function processForbiddenLinkReadCheck(string $link): void
    {
        $forbiddenLinkList = $this->acl
            ->getScopeForbiddenLinkList($this->entityType, AclTable::ACTION_READ);

        if (in_array($link, $forbiddenLinkList)) {
            throw new Forbidden();
        }

        if (in_array($link, $this->forbiddenLinkList)) {
            throw new Forbidden();
        }

        if (in_array($link, $this->internalLinkList)) {
            throw new Forbidden();
        }

        if (!$this->user->isAdmin() && in_array($link, $this->onlyAdminLinkList)) {
            throw new Forbidden();
        }
    }

    protected function processForbiddenLinkEditCheck(string $link): void
    {
        $forbiddenLinkList = $this->acl
            ->getScopeForbiddenLinkList($this->entityType, AclTable::ACTION_EDIT);

        if (in_array($link, $forbiddenLinkList)) {
            throw new Forbidden();
        }

        if (in_array($link, $this->forbiddenLinkList)) {
            throw new Forbidden();
        }

        if (in_array($link, $this->readOnlyLinkList)) {
            throw new Forbidden();
        }

        if (!$this->user->isAdmin() && in_array($link, $this->nonAdminReadOnlyLinkList)) {
            throw new Forbidden();
        }

        if (!$this->user->isAdmin() && in_array($link, $this->onlyAdminLinkList)) {
            throw new Forbidden();
        }
    }

    /**
     * Follow a record.
     *
     * @param $id A record ID.
     * @param $userId A user ID. If not specified then a current user will be used.
     *
     * @throws NotFoundSilent
     * @throws Forbidden
     */
    public function follow(string $id, ?string $userId = null): void
    {
        if (!$this->acl->check($this->entityType, AclTable::ACTION_STREAM)) {
            throw new Forbidden();
        }

        $entity = $this->getRepository()->get($id);

        if (!$entity) {
            throw new NotFoundSilent();
        }

        if (!$this->acl->check($entity, AclTable::ACTION_STREAM)) {
            throw new Forbidden();
        }

        if (empty($userId)) {
            $userId = $this->user->id;
        }

        $this->getStreamService()->followEntity($entity, $userId);
    }

    /**
     * Unfollow a record.
     *
     * @param $id A record ID.
     * @param string|null A user ID. If not specified then a current user will be used.
     *
     * @throws NotFoundSilent
     */
    public function unfollow(string $id, ?string $userId = null): void
    {
        $entity = $this->getRepository()->get($id);

        if (!$entity) {
            throw new NotFoundSilent();
        }

        if (empty($userId)) {
            $userId = $this->user->id;
        }

        $this->getStreamService()->unfollowEntity($entity, $userId);
    }

    protected function getDuplicateWhereClause(Entity $entity, $data)
    {
        return null;
    }

    public function checkIsDuplicate(Entity $entity): bool
    {
        $where = $this->getDuplicateWhereClause($entity, (object) []);

        if ($where) {
            if ($entity->id) {
                $where['id!='] = $entity->id;
            }

            $duplicate = $this->getRepository()
                ->select(['id'])
                ->where($where)
                ->findOne();

            if ($duplicate) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find duplicates for an entity.
     */
    public function findDuplicates(Entity $entity, ?StdClass $data = null): ?Collection
    {
        if (!$data) {
            $data = (object) [];
        }

        $where = $this->getDuplicateWhereClause($entity, $data);

        if (!$where) {
            return null;
        }

        if ($entity->id) {
            $where['id!='] = $entity->id;
        }

        $select = $this->findDuplicatesSelectAttributeList;

        $builder = $this->selectBuilderFactory->create();

        $query = $builder
            ->from($this->entityType)
            ->withStrictAccessControl()
            ->build();

        $duplicateCollection = $this->getRepository()
            ->clone($query)
            ->select($select)
            ->where($where)
            ->limit(0, self::FIND_DUPLICATES_LIMIT)
            ->find();

        if (count($duplicateCollection)) {
            return $duplicateCollection;
        }

        return null;
    }

    /**
     * Run an export.
     *
     * @param Raw export parameters.
     * @return An attachment ID.
     */
    public function export(array $params): string
    {
        if ($this->acl->getPermissionLevel('exportPermission') !== AclTable::LEVEL_YES) {
            throw new ForbiddenSilent("No 'export' permission.");
        }

        if (!$this->acl->check($this->entityType, AclTable::ACTION_READ)) {
            throw new ForbiddenSilent("No 'read' access.");
        }

        $export = $this->injectableFactory->create(ExportTool::class);

        return $export
            ->setRecordService($this)
            ->setParams($params)
            ->setEntityType($this->entityType)
            ->run();
    }

    /**
     * Prepare an entity for output. Clears not allowed attributes.
     *
     * @return void
     */
    public function prepareEntityForOutput(Entity $entity)
    {
        foreach ($this->internalAttributeList as $attribute) {
            $entity->clear($attribute);
        }

        foreach ($this->forbiddenAttributeList as $attribute) {
            $entity->clear($attribute);
        }

        if (!$this->user->isAdmin()) {
            foreach ($this->onlyAdminAttributeList as $attribute) {
                $entity->clear($attribute);
            }
        }

        $forbiddenAttributeList = $this->acl
            ->getScopeForbiddenAttributeList($entity->getEntityType(), AclTable::ACTION_READ);

        foreach ($forbiddenAttributeList as $attribute) {
            $entity->clear($attribute);
        }
    }

    protected function findLinkedFollowers(string $id, SearchParams $params): RecordCollection
    {
        $entity = $this->getRepository()->get($id);

        if (!$entity) {
            throw new NotFound();
        }

        if (!$this->acl->check($entity, AclTable::ACTION_READ)) {
            throw new Forbidden();
        }

        return $this->getStreamService()->findEntityFollowers($entity, $params);
    }

    public function getDuplicateAttributes(string $id): StdClass
    {
        if (!$id) {
            throw new BadRequest("No ID.");
        }

        if (!$this->acl->check($this->entityType, AclTable::ACTION_CREATE)) {
            throw new Forbidden("No 'create' access.");
        }

        if (!$this->acl->check($this->entityType, AclTable::ACTION_READ)) {
            throw new Forbidden("No 'read' access.");
        }

        $entity = $this->getEntity($id);

        if (!$entity) {
            throw new NotFound("Record not found.");
        }

        $attributes = $entity->getValueMap();

        unset($attributes->id);

        $fields = $this->metadata->get(['entityDefs', $this->entityType, 'fields'], []);

        $fieldManager = $this->fieldUtil;

        foreach ($fields as $field => $item) {
            if (!empty($item['duplicateIgnore']) || in_array($field, $this->duplicateIgnoreFieldList)) {
                $attributeToIgnoreList = $fieldManager->getAttributeList($this->entityType, $field);

                foreach ($attributeToIgnoreList as $attribute) {
                    unset($attributes->$attribute);
                }

                continue;
            }

            if (empty($item['type'])) {
                continue;
            }

            $type = $item['type'];

            if (in_array($type, ['file', 'image'])) {
                $attachment = $entity->get($field);

                if ($attachment) {
                    $attachment = $this->entityManager
                        ->getRepository('Attachment')
                        ->getCopiedAttachment($attachment);

                    $idAttribute = $field . 'Id';

                    if ($attachment) {
                        $attributes->$idAttribute = $attachment->id;
                    }
                }
            } else if (in_array($type, ['attachmentMultiple'])) {
                $attachmentList = $entity->get($field);

                if (count($attachmentList)) {
                    $idList = [];
                    $nameHash = (object) [];
                    $typeHash = (object) [];

                    foreach ($attachmentList as $attachment) {
                        $attachment = $this->entityManager
                            ->getRepository('Attachment')
                            ->getCopiedAttachment($attachment);

                        $attachment->set('field', $field);

                        $this->entityManager->saveEntity($attachment);

                        if ($attachment) {
                            $idList[] = $attachment->id;
                            $nameHash->{$attachment->id} = $attachment->get('name');
                            $typeHash->{$attachment->id} = $attachment->get('type');
                        }
                    }

                    $attributes->{$field . 'Ids'} = $idList;
                    $attributes->{$field . 'Names'} = $nameHash;
                    $attributes->{$field . 'Types'} = $typeHash;
                }
            } else if ($type === 'linkMultiple') {
                $foreignLink = $entity->getRelationParam($field, 'foreign');
                $foreignEntityType = $entity->getRelationParam($field, 'entity');

                if ($foreignEntityType && $foreignLink) {
                    $foreignRelationType = $this->metadata->get(
                        ['entityDefs', $foreignEntityType, 'links', $foreignLink, 'type']
                    );

                    if ($foreignRelationType !== 'hasMany') {
                        unset($attributes->{$field . 'Ids'});
                        unset($attributes->{$field . 'Names'});
                        unset($attributes->{$field . 'Columns'});
                    }
                }
            }
        }

        foreach ($this->duplicateIgnoreAttributeList as $attribute) {
            unset($attributes->$attribute);
        }

        $attributes->_duplicatingEntityId = $id;

        return $attributes;
    }

    protected function afterCreateProcessDuplicating(Entity $entity, $data)
    {
        if (!isset($data->_duplicatingEntityId)) {
            return;
        }

        $duplicatingEntityId = $data->_duplicatingEntityId;

        if (!$duplicatingEntityId) {
            return;
        }

        $duplicatingEntity = $this->entityManager->getEntity($entity->getEntityType(), $duplicatingEntityId);

        if (!$duplicatingEntity) {
            return;
        }

        if (!$this->acl->check($duplicatingEntity, AclTable::ACTION_READ)) {
            return;
        }

        $this->duplicateLinks($entity, $duplicatingEntity);
    }

    protected function duplicateLinks(Entity $entity, Entity $duplicatingEntity)
    {
        $repository = $this->getRepository();

        foreach ($this->duplicatingLinkList as $link) {
            $linkedList = $repository->findRelated($duplicatingEntity, $link);

            foreach ($linkedList as $linked) {
                $repository->relate($entity, $link, $linked);
            }
        }
    }

    protected function getFieldByTypeList($type)
    {
        return $this->fieldUtil->getFieldByTypeList($this->entityType, $type);
    }

    public function prepareSearchParams(SearchParams $searchParams): SearchParams
    {
        return $this
            ->prepareSearchParamsSelect($searchParams)
            ->withMaxTextAttributeLength(
                $this->getMaxSelectTextAttributeLength()
            );
    }

    protected function prepareSearchParamsSelect(SearchParams $searchParams): SearchParams
    {
        if ($this->forceSelectAllAttributes) {
            return $searchParams->withSelect(null);
        }

        if ($this->selectAttributeList) {
            return $searchParams->withSelect($this->selectAttributeList);
        }

        if (count($this->mandatorySelectAttributeList) && $searchParams->getSelect() !== null) {
            $select = array_unique(
                array_merge(
                    $searchParams->getSelect(),
                    $this->mandatorySelectAttributeList
                )
            );

            return $searchParams->withSelect($select);
        }

        return $searchParams;
    }

    protected function prepareLinkSearchParams(SearchParams $searchParams, string $link): SearchParams
    {
        if ($searchParams->getSelect() === null) {
            return $searchParams;
        }

        $mandatorySelectAttributeList = $this->linkMandatorySelectAttributeList[$link] ?? null;

        if ($mandatorySelectAttributeList === null) {
            return $searchParams;
        }

        $select = array_unique(
            array_merge(
                $searchParams->getSelect(),
                $mandatorySelectAttributeList
            )
        );

        return $searchParams->withSelect($select);
    }

    protected function beforeCreateEntity(Entity $entity, $data)
    {
    }

    protected function afterCreateEntity(Entity $entity, $data)
    {
    }

    protected function beforeUpdateEntity(Entity $entity, $data)
    {
    }

    protected function afterUpdateEntity(Entity $entity, $data)
    {
    }

    protected function beforeDeleteEntity(Entity $entity)
    {
    }

    protected function afterDeleteEntity(Entity $entity)
    {
    }
}