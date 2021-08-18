BX.ready(function () {
    let formAddButton = BX('addNewSalesPlan'),
        contentForm = BX('contentFormSalesPlan'),
        usersList = [],
        channelsList = [],
        platformsList = [],
        fieldMonthList = null,
        branchName = null,
        formAdd = new BX.PopupWindow(
            'salesPlanAdd',
            null,
            {
                content: contentForm,
                closeIcon: {right: "5px", top: "10px"},
                titleBar: {
                    content: BX.create("span", {
                        html: 'Добавление записи',
                        'props': {'className': 'access-title-bar'}
                    })
                },
                zIndex: 0,
                offsetLeft: 0,
                offsetTop: 0,
                width: 400,
                closeByEsc: true,
                buttons: [
                    new BX.PopupWindowButton({
                        text: "Закрыть",
                        className: "webform-button-link-cancel",
                        events: {
                            click: function () {
                                this.popupWindow.close();
                            }
                        }
                    }),
                    new BX.PopupWindowButton({
                        text: "Сохранить",
                        className: "popup-window-button-accept",
                        events: {
                            click: function () {
                                let popupContainer = this.popupWindow.popupContainer;

                                startLoaderWindowPopup(popupContainer, 'big');

                                usersList = [];
                                channelsList = [];
                                platformsList = [];

                                let fieldUsersList = BX.Main.ui.Factory.get(document.querySelector('[data-name="usersList"]')),
                                    fieldPlatformList = BX.Main.ui.Factory.get(document.querySelector('[data-name="platformsList"]')),
                                    fieldChannelList = BX.Main.ui.Factory.get(document.querySelector('[data-name="channelsList"]')),
                                    trafficValue = BX('trafficListInput').value,
                                    profitValue = BX('profitListInput').value,
                                    amountValue = BX('amountListInput').value,
                                    yearValue = BX('yearsListInput').value;

                                if (fieldUsersList) {
                                    let usersListData = fieldUsersList.instance.getDataValue();

                                    for (let i = 0; i < usersListData.length; i++) {
                                        usersList.push(usersListData[i].VALUE);
                                    }
                                }

                                if (fieldChannelList) {
                                    let channelListData = fieldChannelList.instance.getDataValue();

                                    for (let i = 0; i < channelListData.length; i++) {
                                        channelsList.push(channelListData[i].VALUE);
                                    }
                                }

                                if (fieldPlatformList) {
                                    let platformListData = fieldPlatformList.instance.getDataValue();

                                    for (let i = 0; i < platformListData.length; i++) {
                                        platformsList.push(platformListData[i].VALUE);
                                    }
                                }

                                fieldMonthList = JSON.parse(BX('monthsListDiv').getAttribute('data-value')).VALUE;
                                branchName = JSON.parse(BX('branchListDiv').getAttribute('data-value'));

                                if (branchName !== null){
                                    branchName = branchName.VALUE;
                                }

                                let requestObj = {
                                    action: 'addSalesPlan',
                                    bxSession: BX.message('bitrix_sessid'),
                                    lang: BX.message('LANGUAGE_ID'),
                                    siteId: BX.message('SITE_ID'),
                                    userList: usersList,
                                    platformList: platformsList,
                                    channelList: channelsList,
                                    traffic: trafficValue,
                                    profit: profitValue,
                                    amount: amountValue,
                                    year: yearValue,
                                    month: fieldMonthList,
                                    branchName: branchName
                                }

                                BX.ajax({
                                    url: '/local/components/devino/list.sales_plan/ajax.php',
                                    data: requestObj,
                                    method: 'POST',
                                    async: true,
                                    onsuccess: function (data) {
                                        let response = JSON.parse(data);

                                        if (response.status === false) {
                                            BX.UI.Dialogs.MessageBox.alert(response.error, "Ошибка!");
                                        } else {
                                            gridSalesReload();
                                        }

                                        endLoaderWindowPopup(popupContainer, 'big');
                                    },
                                    onfailure: function () {
                                        console.log('Ошибка при отправке запроса');
                                    }
                                });
                            }
                        }
                    })
                ]
            }
        );

    formAddButton.onclick = function () {
        formAdd.show();
    }
});