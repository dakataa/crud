<?php

namespace Dakataa\Crud\Controller;

interface CrudControllerInterface
{
    const COLUMNS_LIST = 'list';
    const COLUMNS_VIEW = 'view';

    public function getEntityClass(): string;
    public function getEntityTypeClass(): string;
    public function getObjectActions(): array;
}
