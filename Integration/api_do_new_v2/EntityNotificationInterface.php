<?php

interface EntityNotificationInterface {
    /**
     * @param bool $modifyCRMEntity
     * @return mixed
     *
     * выполнение кода нотификаций с сущностями
     */
    public function executeNotification(bool $modifyCRMEntity = false);

    /**
     * @param string $entityType
     * @return mixed
     *
     * сбор нотификаций и данных по каждой сущности
     */
    public function setNotificationsData(string $entityType);
}