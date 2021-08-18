function OnMouseOver(element) {
    let popupId = element.getAttribute('data-popup'),
        frameInfo = document.querySelector('div[data-popup-id="' + popupId + '"]'),
        elemCoordination = element.getBoundingClientRect(),
        coordination = frameInfo.getBoundingClientRect();

    if (elemCoordination.top < 200) {
        frameInfo.style.top = (elemCoordination.bottom) + 'px';
    } else {
        frameInfo.style.top = (elemCoordination.top - coordination.height) + 'px';
    }

    frameInfo.style.left = elemCoordination.left + 'px';
    frameInfo.style.right = elemCoordination.right + 'px';
    frameInfo.style.visibility = 'visible ';
}

function onMouseOut_D(popupId) {
    document.querySelector('div[data-popup-id="' + popupId + '"]').style.visibility = 'hidden';
}

BX.ready(function () {
    BX.addCustomEvent('BX.Main.Filter:apply', BX.delegate(function () {
        window.location.reload();
    }));
})

