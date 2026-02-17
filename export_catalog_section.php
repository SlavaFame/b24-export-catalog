<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Iblock\IblockTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;

$iblockId = 14;
$sectionId = 13;
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$targetUrl = $protocol . '://' . $host . '/import_handler.php';
$maxProductsPerPackage = 2000;

if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
    die('Не удалось загрузить модули iblock или catalog');
}

/**
 * Получить разделы инфоблока
 * @param int $iblockId
 * @param int|null $sectionId
 * @return array
 */
function getSections($iblockId, $sectionId = null) {
    $filter = ['IBLOCK_ID' => $iblockId];

    if ($sectionId !== null) {
        $parentSection = \CIBlockSection::GetByID($sectionId)->Fetch();
        if (!$parentSection) return [];

        $filter['>=LEFT_MARGIN'] = $parentSection['LEFT_MARGIN'];
        $filter['<=RIGHT_MARGIN'] = $parentSection['RIGHT_MARGIN'];
    }

    $sections = [];
    $rsSections = \CIBlockSection::GetList(
        ['LEFT_MARGIN' => 'ASC'],
        $filter,
        false,
        [
            'ID', 'NAME', 'CODE', 'XML_ID', 'DESCRIPTION', 'DESCRIPTION_TYPE',
            'SORT', 'PICTURE', 'DETAIL_PICTURE', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL'
        ]
    );
    while ($section = $rsSections->Fetch()) {
        if (empty($section['XML_ID'])) {
            $section['XML_ID'] = 'SECTION_' . $section['ID'] . '_' . md5($section['NAME'] . time());
        }
        $sections[$section['ID']] = $section;
    }
    return $sections;
}

/**
 * Получить товары из указанных разделов
 * @param int $iblockId
 * @param array $sectionIds
 * @return array
 */
function getProductsFromSections($iblockId, array $sectionIds) {
    if (empty($sectionIds)) return [];

    $products = [];

    $rsElements = \CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID' => $iblockId,
            'SECTION_ID' => $sectionIds,
            'INCLUDE_SUBSECTIONS' => 'Y',
            'ACTIVE' => 'Y',
        ],
        false,
        false,
        [
            'ID', 'IBLOCK_ID', 'NAME', 'CODE', 'XML_ID', 'ACTIVE', 'SORT',
            'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE',
            'PREVIEW_PICTURE', 'DETAIL_PICTURE', 'IBLOCK_SECTION_ID'
        ]
    );

    $elementIds = [];
    $elementData = [];
    while ($element = $rsElements->Fetch()) {
        $elementIds[] = $element['ID'];
        if (empty($element['XML_ID'])) {
            $element['XML_ID'] = 'ELEMENT_' . $element['ID'] . '_' . md5($element['NAME'] . time());
        }
        $elementData[$element['ID']] = $element;
    }

    if (empty($elementIds)) return [];

    $elementSections = [];
    $rsElemSections = \CIBlockElement::GetElementGroups($elementIds, true);
    while ($sectionLink = $rsElemSections->Fetch()) {
        $elementSections[$sectionLink['IBLOCK_ELEMENT_ID']][] = $sectionLink['ID'];
    }

    $propertyValues = [];
    \CIBlockElement::GetPropertyValuesArray($propertyValues, $iblockId, ['ID' => $elementIds]);

    $prices = [];
    $rsPrices = PriceTable::getList([
        'filter' => ['PRODUCT_ID' => $elementIds],
        'select' => ['PRODUCT_ID', 'PRICE', 'CURRENCY', 'CATALOG_GROUP_ID']
    ]);
    foreach ($rsPrices as $price) {
        $prices[$price['PRODUCT_ID']][] = [
            'CATALOG_GROUP_ID' => $price['CATALOG_GROUP_ID'],
            'PRICE' => $price['PRICE'],
            'CURRENCY' => $price['CURRENCY'],
        ];
    }

    $productData = [];
    $rsProducts = ProductTable::getList([
        'filter' => ['ID' => $elementIds],
        'select' => ['ID', 'QUANTITY', 'WEIGHT', 'WIDTH', 'HEIGHT', 'LENGTH', 'MEASURE']
    ]);
    foreach ($rsProducts as $product) {
        $productData[$product['ID']] = $product;
    }

    foreach ($elementIds as $elemId) {
        $product = $elementData[$elemId];
        $product['PROPERTIES'] = $propertyValues[$elemId] ?? [];
        $product['PRICES'] = $prices[$elemId] ?? [];
        $product['CATALOG_PRODUCT'] = $productData[$elemId] ?? [];
        $product['SECTION_IDS'] = $elementSections[$elemId] ?? [];
        $products[$elemId] = $product;
    }

    return $products;
}

/**
 * Отправка пакета с расширенной диагностикой ошибок
 * @param array $packageData
 * @param string $url
 * @return bool|string
 */
function sendPackage(array $packageData, $url) {
    $httpClient = new HttpClient();
    $httpClient->setTimeout(60);
    $httpClient->setStreamTimeout(60);
    $httpClient->disableSslVerification();
    $httpClient->setHeader('Content-Type', 'application/json', true);

    $jsonData = Json::encode($packageData, JSON_UNESCAPED_UNICODE);

    try {
        $response = $httpClient->post($url, $jsonData);
        $status = $httpClient->getStatus();

        if ($status == 200) {
            return true;
        }

        $errorMsg = "HTTP Error: {$status}. ";
        if ($status === 0) {
            $error = $httpClient->getError();
            $errorMsg .= "Connection error: " . ($error ? $error->getMessage() : 'Unknown connection error');
        } else {
            $errorMsg .= "Response: " . substr($response, 0, 500);
        }
        return $errorMsg;

    } catch (\Exception $e) {
        return 'Exception: ' . $e->getMessage();
    }
}

echo "Начинаем экспорт раздела #{$sectionId} из инфоблока #{$iblockId}\n";

$allSections = getSections($iblockId, $sectionId);
if (empty($allSections)) {
    die("Раздел не найден или не имеет подразделов.\n");
}
echo "Найдено разделов: " . count($allSections) . "\n";

$sectionIds = array_keys($allSections);
$allProducts = getProductsFromSections($iblockId, $sectionIds);
$totalProducts = count($allProducts);
echo "Найдено товаров: {$totalProducts}\n";

if ($totalProducts == 0) {
    die("Нет товаров для экспорта.\n");
}

$iblock = IblockTable::getRowById($iblockId);
$packageBase = [
    'iblock' => [
        'ID' => $iblockId,
        'CODE' => $iblock['CODE'],
        'XML_ID' => $iblock['XML_ID'],
        'NAME' => $iblock['NAME'],
    ],
    'sections' => $allSections,
    'products' => [],
];

$productChunks = array_chunk($allProducts, $maxProductsPerPackage, true);
$packageNumber = 1;
$success = true;

foreach ($productChunks as $chunkProducts) {
    echo "Отправка пакета #{$packageNumber} (товаров: " . count($chunkProducts) . ")... ";

    $packageData = $packageBase;
    $packageData['products'] = $chunkProducts;
    $packageData['package'] = [
        'number' => $packageNumber,
        'total' => count($productChunks),
    ];

    $result = sendPackage($packageData, $targetUrl);
    if ($result === true) {
        echo "OK\n";
    } else {
        echo "Ошибка: {$result}\n";
        $success = false;
    }

    $packageNumber++;
}

if ($success) {
    echo "Экспорт завершён успешно.\n";
} else {
    echo "Экспорт завершён с ошибками.\n";
}