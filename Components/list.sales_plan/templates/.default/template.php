<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

/** @var array $arParams */
/** @var array $arResult */
/** @global \CMain $APPLICATION */
/** @global \CUser $USER */
/** @global \CDatabase $DB */
/** @var \CBitrixComponentTemplate $this */
/** @var string $templateName */
/** @var string $templateFile */
/** @var string $templateFolder */
/** @var string $componentPath */
/** @var array $templateData */

/** @var \CBitrixComponent $component */

use Bitrix\Main\Grid\Panel\Snippet;
use Bitrix\Main\LoaderException;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;


$this->addExternalCss($this->GetFolder() . '/style.css');
$this->addExternalJs($this->GetFolder() . '/script.js');

$snippet = new Snippet();

CJSCore::Init(['ui', 'popupWindowFormLoaderDevino']);

try {
    Extension::load("ui.buttons.icons");
    Extension::load("ui.dialogs.messagebox");
    Extension::load('ui.bootstrap4');
    Extension::load("ui.forms");
} catch (LoaderException $e) {
    ShowError($e->getMessage());
}



if (isset($arResult['TYPE_REPORT']['TYPE'])) {
    echo '<div class="devino-filter">';
    $APPLICATION->IncludeComponent(
        'bitrix:main.ui.filter',
        '',
        array(
            'FILTER_ID' => $arResult['FILTER_ID'],
            'FILTER' => $arResult['FILTER_ROWS'],
            'GRID_ID' => $arResult['GRID_ID'],
            'ENABLE_LIVE_SEARCH' => true,
            'ENABLE_LABEL' => true
        ));

    echo '<button class="ui-btn ui-btn-icon-add ui-btn-success" id="addNewSalesPlan" style="margin-left: 15px;">Добавить запись</button>';
    echo '</div>';
}


$APPLICATION->IncludeComponent('bitrix:main.ui.grid', '', [
    'GRID_ID' => $arResult['GRID_ID'],
    'COLUMNS' => $arResult['COLUMNS'],
    'ROWS' => $arResult['ITEMS'],
    'SHOW_ROW_CHECKBOXES' => true,
    'NAV_OBJECT' => $arResult['NAV_OBJECT'],
    'AJAX_MODE' => 'Y',
    'PAGE_SIZES' => array(
        array('NAME' => "5", 'VALUE' => '5'),
        array('NAME' => '10', 'VALUE' => '10'),
        array('NAME' => '20', 'VALUE' => '20'),
        array('NAME' => '50', 'VALUE' => '50'),
        array('NAME' => '100', 'VALUE' => '100')
    ),
    'AJAX_ID' => \CAjax::getComponentID('bitrix:main.ui.grid', '.default', ''),
    'AJAX_OPTION_JUMP' => 'N',
    'ALLOW_COLUMNS_SORT' => true,
    'ALLOW_COLUMNS_RESIZE' => true,
    'ALLOW_HORIZONTAL_SCROLL' => true,
    'ALLOW_SORT' => true,
    'ALLOW_PIN_HEADER' => true,
    'AJAX_OPTION_HISTORY' => 'N',
    'ALLOW_INLINE_EDIT' => true,
    'SHOW_SELECTED_COUNTER' => true,
    'SHOW_PAGESIZE' => true,
    'SHOW_TOTAL_COUNTER' => true,
    'SHOW_PAGINATION' => true,
    'ACTION_PANEL' => array(
        'GROUPS' => array(
            'TYPE' => array(
                'ITEMS' => array(
                    $snippet->getEditButton(),
                    $snippet->getRemoveButton()
                )
            )
        )
    )
]);

?>

<div id="contentFormSalesPlan" style="display: none;">

    <div style="display: flex; justify-items: center; justify-content: space-between;">

        <div data-name="months" id="monthsInput" style="width: 60%;"
             class="main-ui-filter-wield-with-label main-ui-filter-date-group main-ui-control-field-group">
            <label class="main-ui-control-field-label"
                   for="monthsListInput">Месяц <span class="rq">*</span></label>
            <div data-name="monthsList"
                 data-items='<?= Json::encode($this->getComponent()::getMonthsList()); ?>'
                 data-params='<?= Json::encode(['isMulti' => false]); ?>'
                 data-value='<?= Json::encode($this->getComponent()::getCurrentMonth()); ?>'
                 id="monthsListDiv" class="main-ui-control main-ui-select">

                <span class="main-ui-select-name"><?= $this->getComponent()::getCurrentMonth()['NAME'] ?></span>
                <span class="main-ui-square-search">
                    <input type="text" tabindex="2" class="main-ui-square-search-item" name="monthsListInput"
                           id="monthsListInput">
                </span>
            </div>
        </div>

        <div data-name="years" id="yearsInput" style="width: 30%"
             class="main-ui-filter-wield-with-label main-ui-filter-date-group main-ui-control-field-group">
            <label class="main-ui-control-field-label"
                   for="yearsListInput">Год <span class="rq">*</span></label>
            <div class="ui-ctl ui-ctl-textbox">
                <input type="text" class="ui-ctl-element" name="yearsListInput" id="yearsListInput"
                       value="<?= $this->getComponent()::getCurrentYear() ?>">
            </div>
        </div>

    </div>

    <div data-name="users" id="usersInput"
         class="main-ui-filter-wield-with-label main-ui-filter-date-group main-ui-control-field-group">
        <label class="main-ui-control-field-label"
               for="usersListInput">Для кого план <span class="rq">*</span></label>
        <div data-name="usersList"
             data-items='<?= Json::encode($this->getComponent()->getUsers()); ?>'
             data-params='<?= Json::encode(['isMulti' => true]); ?>'
             id="usersListDiv" class="main-ui-control main-ui-multi-select">

            <span class="main-ui-square-container"></span>
            <span class="main-ui-square-search">
                    <input type="text" tabindex="2" class="main-ui-square-search-item" name="usersListInput"
                           id="usersListInput"></span>
            <span class="main-ui-hide main-ui-control-value-delete" style="top: 50%;">
                    <span class="main-ui-control-value-delete-item"></span>
                </span>
        </div>
    </div>

    <div data-name="branch" id="branchInput"
         class="main-ui-filter-wield-with-label main-ui-filter-date-group main-ui-control-field-group">
        <label class="main-ui-control-field-label"
               for="branchListInput">Представительство</label>
        <div data-name="branchList"
             data-items='<?= Json::encode($arResult['BRANCHES_HTML']); ?>'
             data-params='<?= Json::encode(['isMulti' => false]); ?>'
             id="branchListDiv" class="main-ui-control main-ui-select">

            <span class="main-ui-select-name"></span>
            <span class="main-ui-square-search">
                    <input type="text" tabindex="2" class="main-ui-square-search-item" name="branchListInput"
                           id="branchListInput">
                </span>
        </div>
    </div>

    <div data-name="channels" id="channelsInput"
         class="main-ui-filter-wield-with-label main-ui-filter-date-group main-ui-control-field-group">
        <label class="main-ui-control-field-label"
               for="channelsListInput">Канал <span class="rq">*</span></label>
        <div data-name="channelsList"
             data-items='<?= Json::encode($this->getComponent()::getChannelsList()); ?>'
             data-params='<?= Json::encode(['isMulti' => true]); ?>'
             id="channelsListDiv" class="main-ui-control main-ui-multi-select">

            <span class="main-ui-square-container"></span>
            <span class="main-ui-square-search">
                    <input type="text" tabindex="2" class="main-ui-square-search-item" name="channelsListInput"
                           id="channelsListInput"></span>
            <span class="main-ui-hide main-ui-control-value-delete" style="top: 50%;">
                    <span class="main-ui-control-value-delete-item"></span>
                </span>
        </div>
    </div>

    <div data-name="platforms" id="platformsInput"
         class="main-ui-filter-wield-with-label main-ui-filter-date-group main-ui-control-field-group">
        <label class="main-ui-control-field-label"
               for="platformsListInput">Платформа <span class="rq">*</span></label>
        <div data-name="platformsList"
             data-items='<?= Json::encode($this->getComponent()::getPlatformsList()); ?>'
             data-params='<?= Json::encode(['isMulti' => true]); ?>'
             id="platformsListDiv" class="main-ui-control main-ui-multi-select">

            <span class="main-ui-square-container"></span>
            <span class="main-ui-square-search">
                    <input type="text" tabindex="2" class="main-ui-square-search-item" name="platformsListInput"
                           id="platformsListInput"></span>
            <span class="main-ui-hide main-ui-control-value-delete" style="top: 50%;">
                    <span class="main-ui-control-value-delete-item"></span>
                </span>
        </div>
    </div>

    <div data-name="profit" id="profitInput"
         class="main-ui-filter-wield-with-label main-ui-filter-date-group main-ui-control-field-group">
        <label class="main-ui-control-field-label"
               for="profitListInput">Профит <span class="rq">*</span></label>
        <div class="ui-ctl ui-ctl-textbox" style="width: 100%">
            <input type="text" class="ui-ctl-element" name="profitListInput" id="profitListInput">
        </div>
    </div>

    <div data-name="traffic" id="trafficInput"
         class="main-ui-filter-wield-with-label main-ui-filter-date-group main-ui-control-field-group">
        <label class="main-ui-control-field-label"
               for="trafficListInput">Трафик <span class="rq">*</span></label>
        <div class="ui-ctl ui-ctl-textbox" style="width: 100%">
            <input type="text" class="ui-ctl-element" name="trafficListInput" id="trafficListInput">
        </div>
    </div>

    <div data-name="amount" id="amountInput"
         class="main-ui-filter-wield-with-label main-ui-filter-date-group main-ui-control-field-group">
        <label class="main-ui-control-field-label"
               for="amountListInput">Выручка <span class="rq">*</span></label>
        <div class="ui-ctl ui-ctl-textbox" style="width: 100%">
            <input type="text" class="ui-ctl-element" name="amountListInput" id="amountListInput">
        </div>
    </div>

</div>


<script>
    function gridSalesReload() {
        BX.Main.gridManager.reload('<?=$arResult['GRID_ID']?>');
    }

    BX.addCustomEvent('UI::Select::change', function (obj) {
        selectName = BX.data(obj.node, 'name');
        valueList = obj.getDataValue();

        let isChanged = false;

        switch (selectName) {
            case 'usersList':
                let domElement = document.getElementById('branchInput');

                for (let el in valueList) {
                    if (valueList[el].VALUE !== "sales_plan") {

                        if (domElement.style.display !== 'none') {
                            domElement.style.display = 'none';
                        }

                        isChanged = true;
                    }
                }

                if (isChanged === false) {
                    domElement.style.display = 'block';
                }
                break;
        }
    });
</script>



