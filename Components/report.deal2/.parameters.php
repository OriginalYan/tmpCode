<?
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

$arComponentParameters = array(
    "GROUPS" => array(),
    "PARAMETERS" => array(
        "TYPE_COMPONENT" => array(
            "PARENT" => "BASE",
            "NAME" => "Тип отчета",
            "TYPE" => "LIST",
            "VALUES" => array(
                "DEAL" => "Сделки",
                "COMPANY" => "Компании"
            ),
            "DEFAULT" => "DEAL"
        ),
        "CACHE_TIME" => array(),
    ),
);