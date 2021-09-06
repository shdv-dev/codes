<?php
			
namespace ELDirect\Store;

set_time_limit(50);

use \Bitrix\Main\Type,
	\Bitrix\Main\Loader,
	\Bitrix\Main\Config\Option,
	\Bitrix\Main\IO\Directory,
	\Bitrix\Main\IO\File,
	\Bitrix\Main\Web,
	\Bitrix\Iblock\SectionTable,
	\Bitrix\Iblock\ElementTable,
	\Bitrix\Iblock\PropertyIndex,
	\Bitrix\Highloadblock\HighloadBlockTable,
	\Bitrix\Catalog\ProductTable,
	\Bitrix\Catalog\Model\Price,
	\Bitrix\Catalog\StoreProductTable,
	\Bitrix\Catalog\StoreBarcodeTable;

class Synchronization {
	// Data
	private static $authToken           = '*********';
	private static $urlCatalog          = 'https://*****/api/*****/get-product-catalog';
	private static $urlCollections      = 'https://*****/api/*****/get-collections';
	private static $urlStock            = 'https://*****/api/*****/get-stor-rest';
	// Time
	private static $timeExec            = 25;
	private static $timeIntCatalog      = 60*60*6;
	private static $timeIntStock        = 60*30;
	private static $timeStartCatalog    = '03:00';
	private static $timeEndCatalog      = '03:30';
	// Catalog
	private static $iblockCatalogId     = 35;
	private static $iblockSkuId         = 36;
	private static $iblockCollectionId  = 37;
	// Stock
	private static $stockId             = 3;
	// Prices
	private static $currencyCode        = 'RUB';
	private static $vatId               = 1;
	private static $priceCoefficient    = 1;
	// Dir
	private static $dirSynch            = 'synch';
	private static $dirLog              = 'log';
	private static $dirCatalog          = 'catalog';
	private static $dirStock            = 'stock';
	private static $dirImages           = 'images';
	// File
	private static $paramsCatalog       = 'catalog.json';
	private static $paramsStock         = 'stock.json';
	// Lang
	private static $langTranslit        = 'ru';
	// Processing limits
	private static $cntLimitCatalog     = 0;
	private static $cntLimitCollections = 0;
	private static $cntLimitStock       = 0;
	// Defaults
	public static $defaultWeight        = 200;
	public static $defaultWidth         = 25;
	public static $defaultLength        = 35;
	public static $defaultHeight        = 5;
	// Sections
	public static $sectionMatrix        = 'Products';
	public static $sectionDiscount      = 'Actions';
	public static $sectionDiscountSub   = 'Discount';
	public static $sectionUndefined     = 'Undefined';


	public static $session              = '';
	public static $arSession            = [];
	public static $arParams             = [];

	private static function writeLog($line = "", $msg = "", $varName = "", $varValue = "")
	{
		$path = str_replace("//", "/", implode("/", [
			$_SERVER["DOCUMENT_ROOT"],
			Option::get("main", "upload_dir", "upload"),
			self::$dirSynch,
			self::$dirLog,
			""
		]));
		$file = str_replace("//", "/", implode("/", [$path, "log-".date("Y-m-d").".txt"]));
		$var = "";
		if (is_array($varValue))
		{
			unset($varValue["data"]);
			foreach ($varValue as $k => $v)
			{
				if (strlen($var) > 0)
				{
					$var .= "; ";
				}
				$var .= $k.": ";
				if (is_array($v))
				{
					$var .= var_export(json_encode($v), true);
				} else {
					$var .= var_export($v, true);
				}
			}
		} else {
			$var = var_export($varValue, true);
		}
		while(strlen($line) < 5)
		{
			$line = "0".$line;
		}
		$mt = getmicrotime();
		$mt = explode(".", $mt);
		while(strlen($mt[1]) < 5)
		{
			$mt[1] = $mt[1]."0";
		}
		while(strlen($msg) < 20)
		{
			$msg = $msg." ";
		}
		while(strlen($varName) < 15)
		{
			$varName = $varName." ";
		}
		$mt = implode(".", $mt);
		$writeline = [];
		$writeline['t'] = date("d.m.Y H:i:s");
		$writeline['f'] = $GLOBALS["SYNCH_TYPE"] ? ToUpper($GLOBALS["SYNCH_TYPE"]) : 'U';
		$writeline['u'] = $mt;
		$writeline['l'] = $line;
		$writeline['m'] = $msg;
		$writeline['n'] = $varName;
		$writeline['v'] = $var;
		$writeline['s'] = "\n";
		File::putFileContents($file, implode("   ", $writeline), File::APPEND);
		return true;
	}

	private static function getParamsFile()
	{
		$type = (ToUpper($GLOBALS["SYNCH_TYPE"]) == "S" ? self::$paramsStock : self::$paramsCatalog);
		$path = [$_SERVER["DOCUMENT_ROOT"], Option::get("main", "upload_dir", "upload"), self::$dirSynch];
		$path = str_replace("//", "/", implode("/", array_merge($path, [""])));
		$file = str_replace("//", "/", implode("/", [$path, $type]));
		return $file;
	}

	private static function setParams()
	{
		self::writeLog(__LINE__, 'setParams', 'arParams', self::$arParams);
		$res = File::putFileContents(self::getParamsFile(), json_encode(self::$arParams));
	}

	private static function getParams()
	{
		$file = self::getParamsFile();
		self::writeLog(__LINE__, 'getParams', 'file', $file);
		$params = false;
		if (File::isFileExists($file))
		{
			$params = File::getFileContents($file);
			if (strlen($params) > 0)
			{
				$params = json_decode($params, true);
			}
		}
		self::writeLog(__LINE__, 'getParams', 'params', $params);
		$createParams = false;
		if (
			is_array($params) &&
			array_key_exists("start", $params) &&
			array_key_exists("finish", $params) &&
			array_key_exists("exec", $params)
		)
		{
			$td = 0;
			if (floatval($params["start"]) > floatval($params["finish"]))
			{
				$td = getmicrotime() - floatval($params["start"]); 
			}
			$tm = (ToUpper($GLOBALS["SYNCH_TYPE"]) == "S" ? (self::$timeIntStock * 3) : self::$timeIntCatalog);
			if ($td < $tm)
			{
				self::$arParams = $params;
			} else {
				$createParams = true;
			}
			self::writeLog(__LINE__, 'getParams', 'vars', ['td' => $td, 'tm' => $tm]);
		} else {
			$createParams = true;
		}
		self::writeLog(__LINE__, 'getParams', 'createParams', $createParams);
		if ($createParams)
		{
			self::$arParams = [
				"start" => 0,
				"finish" => 0,
				"exec" => 0,
			];
			self::setParams();
			self::$session = md5(getmicrotime());
			self::getSession();
		}
	}
	
	private static function mainSynchExecuting()
	{
		$result = false;
		$path = str_replace("//", "/", implode("/", [
			$_SERVER["DOCUMENT_ROOT"],
			Option::get("main", "upload_dir", "upload"),
			self::$dirSynch,
			""
		]));
		$file = str_replace("//", "/", implode("/", [$path, self::$paramsCatalog]));
		if (File::isFileExists($file))
		{
			$params = File::getFileContents($file);
			if (strlen($params) > 0)
			{
				$params = json_decode($params, true);
				if (floatval($params["finish"]) < floatval($params["start"]))
				{
					$result = true;
				}
			}
		}
		self::writeLog(__LINE__, 'mainSynchExecuting', 'result', $result);
		return $result;
	}

	private static function getSessionDir()
	{
		$type = (ToUpper($GLOBALS["SYNCH_TYPE"]) == "S" ? self::$dirStock : self::$dirCatalog);
		$path = [$_SERVER["DOCUMENT_ROOT"], Option::get("main", "upload_dir", "upload"), self::$dirSynch, $type];
		$path = str_replace("//", "/", implode("/", array_merge($path, [""])));
		return $path;
	}

	private static function getSessionFile()
	{
		$path = self::getSessionDir();
		$file = str_replace("//", "/", implode("/", [$path, self::$session.".json"]));
		return $file;
	}

	private static function setSession()
	{
		self::writeLog(__LINE__, 'setSession', 'arSession', self::$arSession);
		$file = self::getSessionFile();
		$res = File::putFileContents($file, json_encode(self::$arSession));
		if (!$res || !File::isFileExists($file))
		{
			self::$session = "";
			self::$arSession = [];
		}
	}

	private static function getSession()
	{
		$createSession = true;
		$params = [];
		if (strlen(self::$session) > 0)
		{
			$file = self::getSessionFile();
			self::writeLog(__LINE__, 'getSession', 'file', $file);
			if (File::isFileExists($file))
			{
				$params = File::getFileContents($file);
				if (strlen($params) > 0)
				{
					$params = json_decode($params, true);
				}
				self::writeLog(__LINE__, 'getSession', 'params', $params);
				if (
					is_array($params) &&
					array_key_exists("session", $params)  &&
					array_key_exists("step", $params)
				)
				{
					if ($params["session"] == self::$session)
					{
						$createSession = false;
						self::$arSession = $params;
					}
				}
			}
		}
		self::writeLog(__LINE__, 'getSession', 'createSession', $createSession);
		if ($createSession)
		{
			$params = [
				"session" => md5(getmicrotime()),
				"step" => 0,
				"pos" => 0,
				"data" => [],
			];
			$path = self::getSessionDir();
			Directory::deleteDirectory($path);
			self::$arSession = $params;
			self::$session = $params["session"];
			self::setSession();
		}
	}

	private static function downloadData($url = "")
	{
		$result = false;
		if (strlen($url))
		{
			$curl = curl_init($url);
			curl_setopt_array(
				$curl,
				[
					CURLOPT_HTTPHEADER => [
						"Content-Type: application/json",
						sprintf('Authorization: Bearer %s', self::$authToken)
					],
					CURLOPT_CUSTOMREQUEST => "GET",
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => 1,
				]
			);
			$result = curl_exec($curl);
			curl_close($curl);
		}
		return $result;
	}

	private static function getCharacterCode($type = "S", $iblock = 0, $text = "", $add = "")
	{
		$result = false;
		$text = str_replace("  ", " ", trim($text));
		$add = str_replace("  ", " ", trim($add));
		$class = (ToUpper($type) == "E") ? '\Bitrix\Iblock\ElementTable' : '\Bitrix\Iblock\SectionTable';
		if ($iblock > 0 && strlen($text) > 0 && Loader::includeModule("iblock"))
		{
			$params = [
				"replace_space" => "-",
				"replace_other" => "-",
			];
			$temp = \CUtil::translit($text, self::$langTranslit, $params);
			$res = $class::getList([
				"select" => ["ID"],
				"filter" => [
					"IBLOCK_ID" => $iblock,
					"CODE" => $temp,
				]
			]);
			if ($arRes = $res->fetch())
			{
				$temp = \CUtil::translit(
					$text." ".(strlen($add) > 0 ? $add : randString(3)),
					self::$langTranslit,
					$params
				);
				$res = $class::getList([
					"select" => ["ID"],
					"filter" => [
						"IBLOCK_ID" => $iblock,
						"CODE" => $temp,
					]
				]);
				if ($arRes = $res->fetch())
				{
					$temp = \CUtil::translit(
						$text." ".(strlen($add) > 0 ? $add : randString(3))." ".randString(5),
						self::$langTranslit,
						$params
					);
					$res = $class::getList([
						"select" => ["ID"],
						"filter" => [
							"IBLOCK_ID" => $iblock,
							"CODE" => $temp,
						]
					]);
					if ($arRes = $res->fetch()) {
						$temp = $temp."-".md5(time());
					}
				}
			}
			$result = $temp;
		}
		return $result;
	}

	private static function getCollectionSection($collectionId = 0)
	{
		$result = false;
		$collectionId = intval($collectionId);
		if ($collectionId > 0 && Loader::includeModule("iblock"))
		{
			$xmlid = md5(implode("+", [
				"T:"."COLLECTION",
				"A:".$collectionId,
			]));
			$res = \CIBlockElement::GetList(
				["ID" => "ASC"],
				[
					"IBLOCK_ID" => self::$iblockCollectionId,
					"XML_ID" => $xmlid,
				],
				false,
				["nTopCount" => 1],
				["ID", "IBLOCK_SECTION_ID"]
			);
			if ($arRes = $res->GetNext())
			{
				if (intval($arRes["IBLOCK_SECTION_ID"]) > 0)
				{
					$res = \CIBlockSection::GetList(
						["ID" => "ASC"],
						[
							"IBLOCK_ID" => self::$iblockCollectionId,
							"ID" => $arRes["IBLOCK_SECTION_ID"],
						],
						false,
						["ID", "NAME"],
						["nTopCount" => 1]
					);
					if ($arRes = $res->GetNext())
					{
						$result = $arRes["NAME"];
					}
				}
			}
		}
		return $result;
	}

	private static function getSectionId($iblock = 0, $path = [])
	{
		$result = false;
		if (Loader::includeModule("iblock"))
		{
			if (!is_array($path))
			{
				$path = strlen($path) ? [$path] : [];
			}
			$path = array_diff($path, [""]);
			if ($iblock > 0 && !empty($path))
			{
				$xmlid = "";
				$lastElement = end($path);
				if (is_array($lastElement) && strlen($lastElement["xmlid"]))
				{
					$xmlid = $lastElement["xmlid"];
				} else {
					$tmp = [];
					foreach($path as $p)
					{
						$tmp[] = (is_array($p) ? (strlen($p["xmlid"]) ? $p["xmlid"] : $p["name"]) : $p);
					}
					if (!empty($tmp))
					{
						$xmlid = implode("/", $tmp);
					}
				}
				$sid = 0;
				$res = SectionTable::getList([
					"select" => ["ID"],
					"filter" => [
						"IBLOCK_ID" => $iblock,
						"XML_ID" => $xmlid,
					],
				]);
				if ($arRes = $res->fetch())
				{
					$sid = $arRes["ID"];
				} else {
					$tmp = [];
					$name = [];
					foreach ($path as $p)
					{
						$tmp[] = (is_array($p) ? (strlen($p["xmlid"]) ? $p["xmlid"] : $p["name"]) : $p);
						$name[] = is_array($p) ? $p["name"] : $p;
						$res = SectionTable::getList([
							"select" => ["ID"],
							"filter" => [
								"IBLOCK_ID" => $iblock,
								"XML_ID" => md5(implode("/", $tmp))
							]
						]);
						if ($arRes = $res->fetch())
						{
							$sid = $arRes["ID"];
						} else {
							$arSection = [
								"ACTIVE" => "Y",
								"IBLOCK_ID" => $iblock,
								"SORT" => (is_array($p) && $p["sort"] > 0) ? $p["sort"] : 500,
								"XML_ID" => (is_array($p) && strlen($p["xmlid"]))
									? $p["xmlid"]
									: md5(implode("/", $tmp)),
								"NAME" => is_array($p) ? $p["name"] : $p,
								"CODE" => self::getCharacterCode("s", $iblock, implode(" ", $name)),
							];
							if ($sid > 0)
							{
								$arSection["IBLOCK_SECTION_ID"] = $sid;
							}
							$sec = new \CIBlockSection;
							$sid = $sec->Add($arSection);
							$sid = intval($sid);
							if (!$sid)
							{
								$sid = false;
								break;
							}
						}
					}
				}
				$result = $sid;
			}
		}
		return $result;
	}

	private static function getElementId($iblockId = 0, $xmlid = "", $fields = [])
	{
		$result = false;
		if ($iblockId > 0 && strlen($xmlid))
		{
			if (!is_array($fields))
			{
				$fields = strlen($fields) ? [$fields] : [];
			}
			if (empty($fields))
			{
				$fields[] = "ID";
			}
			$res = \CIBlockElement::getList(
				["ID" => "ASC"],
				[
					"IBLOCK_ID" => $iblockId,
					"XML_ID" => $xmlid,
				],
				false,
				["nTopCount" => 1],
				$fields
			);
			if ($arRes = $res->GetNext())
			{
				if (count($fields) == 1 && array_key_exists("ID", $fields))
				{
					$result = $arRes["ID"];
				} else {
					$result = $arRes;
				}
			}
		}
		return $result;
	}

	private static function getPropertyEnumId($iblock = 0, $code = "", $value = "")
	{
		$pid = "";
		$code = trim($code);
		$value = trim($value);
		$xmlid = md5("enum_".ToUpper($value));
		if (Loader::includeModule("iblock"))
		{
			if ($iblock > 0 && strlen($code) > 0 && strlen($value) > 0)
			{
				$res = \CIBlockPropertyEnum::GetList(
					["ID" => "ASC"],
					[
						"IBLOCK_ID" => $iblock,
						"CODE" => $code,
						"XML_ID" => $xmlid,
					]
				);
				if ($arRes = $res->GetNext())
				{
					$pid = intval($arRes["ID"]);
				} else {
					$res = \CIBlockProperty::GetList(
						["ID" => "ASC"],
						[
							"IBLOCK_ID" => $iblock,
							"CODE" => $code,
							"PROPERTY_TYPE" => "L",
						]
					);
					if ($arRes = $res->GetNext())
					{
						$arPropEnum = [
							"PROPERTY_ID" => intval($arRes["ID"]),
							"VALUE" => $value,
							"XML_ID" => $xmlid,
						];
						$enum = new \CIBlockPropertyEnum;
						if ($pid = $enum->Add($arPropEnum))
						{
							$pid = intval($pid);
						}
					}
				}
			}
		}
		return intval($pid) > 0 ? $pid : "";
	}

	private static function getPropertyRefId($iblock = 0, $code = "", $value = "", $description = "", $strId = "",
											 $imgUrl = "")
	{
		$pid = "";
		$code = trim($code);
		$value = trim($value);
		$description = trim($description);
		$strId = trim($strId);
		$imgUrl = trim($imgUrl);
		$xmlid = md5("ref_".(strlen($strId) > 0 ? $strId : ToUpper($value)));
		if (Loader::includeModule('iblock') && Loader::includeModule('highloadblock'))
		{
			$hlblock = false;
			$entity = false;
			$class = false;
			if ($iblock > 0 && strlen($code) > 0 && strlen($value) > 0)
			{
				$res = \CIBlockProperty::GetList(
					["ID" => "ASC"],
					[
						"IBLOCK_ID" => $iblock,
						"CODE" => $code,
						"USER_TYPE" => "directory"
					]
				);
				if ($arRes = $res->GetNext())
				{
					if (strlen(trim($arRes["USER_TYPE_SETTINGS"]["TABLE_NAME"])))
					{
						$res = HighloadBlockTable::getList([
							"select" => ["ID"],
							"filter" => [
								"TABLE_NAME" => trim($arRes["USER_TYPE_SETTINGS"]["TABLE_NAME"]),
							],
						]);
						if ($arRes = $res->fetch())
						{
							$hlblock = HighloadBlockTable::getById($arRes["ID"])->fetch();
							if ($hlblock)
							{
								$entity = HighloadBlockTable::compileEntity($hlblock);
								if ($entity)
								{
									$class = $entity->getDataClass();
								}
							}
						}
					}
				}
			}
			if ($hlblock && $entity && $class)
			{
				$res = $class::getList([
					"select" => ["UF_XML_ID"],
					"filter" => [
						"UF_XML_ID" => $xmlid,
					],
				]);
				if ($arRes = $res->fetch())
				{
					$pid = $arRes["UF_XML_ID"];
				} else {
					$fileId = false;
					$arImage = false;
					if (strlen($imgUrl))
					{
						$arImage = \CFile::MakeFileArray($imgUrl);
						$arImage['MODULE_ID'] = 'highloadblock';
						//$fileId = \CFile::SaveFile($arImage, "highloadblock");
					}
					$arPropRef = [
						"UF_NAME" => $value,
						"UF_XML_ID" => $xmlid,
						"UF_DESCRIPTION" => $description,
						"UF_IMAGE" => $arImage,
					];
					$res = $class::add($arPropRef);
					if ($pid = $res->getId())
					{
						$pid = intval($xmlid);
					}
				}
			}
		}
		return $pid;
	}

	private static function getPropertyRefVal($iblock = 0, $code = "", $xmlid = "")
	{
		$val = "";
		$code = trim($code);
		$xmlid = trim($xmlid);
		if (Loader::includeModule('iblock') && Loader::includeModule('highloadblock'))
		{
			$hlblock = false;
			$entity = false;
			$class = false;
			if ($iblock > 0 && strlen($code) > 0 && strlen($xmlid) > 0)
			{
				$res = \CIBlockProperty::GetList(
					["ID" => "ASC"],
					["IBLOCK_ID" => $iblock, "CODE" => $code, "USER_TYPE" => "directory"]
				);
				if ($arRes = $res->GetNext())
				{
					if (strlen(trim($arRes["USER_TYPE_SETTINGS"]["TABLE_NAME"])) > 0)
						$res = HighloadBlockTable::getList([
							"select" => ["ID"],
							"filter" => ["TABLE_NAME" => trim($arRes["USER_TYPE_SETTINGS"]["TABLE_NAME"])],
						]);
					if ($arRes = $res->fetch())
					{
						$hlblock = HighloadBlockTable::getById($arRes["ID"])->fetch();
						if ($hlblock)
						{
							$entity = HighloadBlockTable::compileEntity($hlblock);
							if ($entity)
							{
								$class = $entity->getDataClass();
							}
						}
					}
				}
			}
			if ($hlblock && $entity && $class)
			{
				$res = $class::getList([
					"select" => ["UF_XML_ID", "UF_NAME"],
					"filter" => ["UF_XML_ID" => $xmlid],
				]);
				if ($arRes = $res->fetch())
				{
					$val = $arRes["UF_NAME"];
				}
			}
		}
		return $val;
	}

	private static function getProductId($type = "E", $pid = 0)
	{
		// E - element, S - sku
		$type = (ToUpper($type) == "S") ? ProductTable::TYPE_OFFER : ProductTable::TYPE_PRODUCT;
		if ($pid > 0)
		{
			if (Loader::includeModule("catalog"))
			{
				$res = ProductTable::getList([
					'order' => ["ID" => "ASC"],
					'filter' => ["ID" => $pid],
				]);
				if ($arRes = $res->fetch())
				{
					$pid = intval($arRes["ID"]);
				} else {
					$arFields = [
						"ID" => $pid,
						"AVAILABLE" => "Y",
						"TYPE" => $type,
					];
					if ($type == ProductTable::TYPE_OFFER)
					{
						$arFields = array_merge($arFields, [
							"QUANTITY" => 0,
							"QUANTITY_RESERVED" => 0,
							"QUANTITY_TRACE" => "D",
							"CAN_BUY_ZERO" => "D",
							"VAT_ID" => self::$vatId,
							"VAT_INCLUDED" => "Y",
						]);
					}
					$res = ProductTable::add($arFields);
					if ($res->isSuccess())
					{
						$pid = intval($arFields["ID"]);
					} else {
						$pid = false;
					}
				}
			}
		} else {
			$pid = false;
		}
		return $pid;
	}

	private static function setProductPrice($type = "e", $pid = 0, $price = 0)
	{
		$type = (ToUpper($type) == "S") ? "S" : "E";
		$upd = false;
		$price = ceil($price);
		if (Loader::includeModule("catalog"))
		{
			if (intval($pid) > 0)
			{
				$pid = self::getProductId($type, $pid);
				$basePriceId = \CCatalogGroup::GetBaseGroup();
				if (is_array($basePriceId) && intval($basePriceId["ID"]) > 0)
				{
					$basePriceId = intval($basePriceId["ID"]);
				} else {
					$basePriceId = false;
				}
				if (intval($pid) > 0 && intval($basePriceId) > 0)
				{
					$arFields = [
						"PRODUCT_ID" => $pid,
						"CATALOG_GROUP_ID" => $basePriceId,
						"PRICE" => $price,
						"CURRENCY" => self::$currencyCode,
					];
					$res = Price::getList([
						"filter" => [
							"PRODUCT_ID" => $pid,
							"CATALOG_GROUP_ID" => $basePriceId,
						]
					]);
					if ($arRes = $res->fetch())
					{
						$res = Price::update($arRes["ID"], $arFields);
						$upd = $res->isSuccess();
					} else {
						$res = Price::add($arFields);
						$upd = $res->isSuccess();
					}
				}
			}
		}
		return $upd;
	}

	private static function setProductWeight($type = "e", $pid = 0, $weight = 0)
	{
		$type = (ToUpper($type) == "S") ? "S" : "E";
		$pid = intval($pid);
		$upd = false;
		if (Loader::includeModule("catalog"))
		{
			if ($pid > 0)
			{
				$pid = self::getProductId($type, $pid);
				if ($pid > 0)
				{
					$res = ProductTable::getList([
						"select" => ["ID"],
						"filter" => ["ID" => $pid],
						"order" => ["ID" => "ASC"],
					]);
					if ($arRes = $res->fetch())
					{
						$res = ProductTable::update($arRes["ID"], ["WEIGHT" => intval($weight)]);
						$upd = $res->isSuccess();
					}
				}
			}
		}
		return $upd;
	}

	private static function setProductDimensions($type = "e", $pid = 0, $width = 0, $length = 0, $height = 0)
	{
		$type = (ToUpper($type) == "S") ? "S" : "E";
		$pid = intval($pid);
		$upd = false;
		if (Loader::includeModule("catalog"))
		{
			if ($pid > 0)
			{
				$pid = self::getProductId($type, $pid);
				if ($pid > 0)
				{
					$res = ProductTable::getList([
						"select" => ["ID"],
						"filter" => ["ID" => $pid],
						"order" => ["ID" => "ASC"],
					]);
					if ($arRes = $res->fetch())
					{
						$res = ProductTable::update(
							$arRes["ID"],
							[
								"WIDTH" => intval($width),
								"LENGTH" => intval($length),
								"HEIGHT" => intval($height),
							]
						);
						$upd = $res->isSuccess();
					}
				}
			}
		}
		return $upd;
	}

	private static function setProductRest($pid = 0, $rest = 0)
	{
		$result = false;
		if (Loader::includeModule("iblock") && Loader::includeModule("catalog"))
		{
			if (intval($pid) > 0)
			{
				$pid = self::getProductId("s", $pid);
				if ($pid > 0)
				{
					$res = StoreProductTable::getList([
						"order" => ["ID" => "ASC"],
						"filter" => ["PRODUCT_ID" => $pid],
					]);
					if ($arRes = $res->fetch())
					{
						$res = StoreProductTable::update($arRes["ID"], ["AMOUNT" => intval($rest)]);
					} else {
						$res = StoreProductTable::add([
							"STORE_ID" => self::$stockId,
							"PRODUCT_ID" => $pid,
							"AMOUNT" => intval($rest),
						]);
					}
					$result = $res->isSuccess();
					if ($result)
					{
						$res = ProductTable::getList([
							'order' => ["ID" => "ASC"],
							'filter' => ["ID" => $pid],
						]);
						if ($arRes = $res->fetch())
						{
							$availability = ProductTable::calculateAvailable([
								"QUANTITY" => intval($rest),
								"QUANTITY_TRACE" => ProductTable::STATUS_DEFAULT,
								"CAN_BUY_ZERO" => ProductTable::STATUS_DEFAULT,
							]);
							$res = ProductTable::update(
								$arRes["ID"],
								[
									"QUANTITY" => intval($rest),
									"AVAILABLE" => $availability,
								]
							);
							$result = $res->isSuccess();
							$skuStatus = "N";
							$sku = \CIBlockElement::GetList(
								["ID" => "ASC"],
								[
									"IBLOCK_ID" => self::$iblockSkuId,
									"ID" => $pid,
								],
								false,
								["nTopCount" => 1],
								["ID", "PROPERTY_STATUS"]
							);
							if ($arSku = $sku->GetNext())
							{
								$skuStatus = $arSku["PROPERTY_STATUS_VALUE"] == "Y" ? "Y" : "N";
							}
							$elm = new \CIBlockElement;
							$elm->Update($pid, [
								"ACTIVE" => ($rest > 0 && $availability == "Y" && $skuStatus == "Y") ? "Y" : "N",
							]);
							unset($elm);
						}
					}
					$res = \CIBlockElement::GetList(
						["ID" => "ASC"],
						[
							"IBLOCK_ID" => self::$iblockSkuId,
							"ID" => $pid,
						],
						false,
						["nTopCount" => 1],
						["ID", "PROPERTY_CML2_LINK"]
					);
					if ($arRes = $res->GetNext())
					{
						$eid = intval($arRes["PROPERTY_CML2_LINK_VALUE"]);
						//if ($skuActive)
						if ($eid > 0)
						{
							$active = "N";
							$status = "N";
							$res = \CIBlockElement::GetList(
								["ID" => "ASC"],
								[
									"IBLOCK_ID" => self::$iblockCatalogId,
									"ID" => $eid,
								],
								false,
								["nTopCount" => 1],
								["ID", "ACTIVE", "PROPERTY_PHOTOS"]
							);
							if ($arRes = $res->GetNext())
							{
								$active = $arRes["ACTIVE"];
								if (strlen(trim($arRes["PROPERTY_PHOTOS_VALUE"])) > 0)
								{
									$res = \CIBlockElement::GetList(
										["ID" => "ASC"],
										[
											"IBLOCK_ID" => self::$iblockSkuId,
											"ACTIVE" => "Y",
											">PRICE" => 0,
											"PROPERTY_CML2_LINK" => $eid,
											"=PROPERTY_STATUS" => "Y",
										],
										false,
										["nTopCount" => 1],
										["ID"]
									);
									if ($arRes = $res->GetNext())
									{
										$status = "Y";
									}
								}
							}
							if ($active != $status)
							{
								$elm = new \CIBlockElement;
								$elm->Update($eid, [
									"ACTIVE" => $status
								]);
								unset($elm);
							}
						}
					}
				}
			}
		}
		return $result;
	}

	private static function processCatalog($session = '')
	{
		$GLOBALS["SYNCH_TYPE"] = "C";
		self::$session = $session;
		self::getParams();
		self::getSession();
		if ((getmicrotime() - (float)self::$arParams["finish"]) < self::$timeIntCatalog)
		{
			self::writeLog(__LINE__, 'processCatalog', 'update', 'false');
			return md5(getmicrotime());
		} else {
			$ts0 = intval(date("Hi"));
			$ts1 = intval(preg_replace('/[^0-9]/', '', self::$timeStartCatalog));
			$ts2 = intval(preg_replace('/[^0-9]/', '', self::$timeEndCatalog));
			self::writeLog(__LINE__, 'processCatalog', 'ts', ['ts0' => $ts0, 'ts1' => $ts1, 'ts2' => $ts2]);
			$canExec = false;
			if ($ts0 > $ts1 && $ts0 < $ts2)
			{
				$canExec = true;
				self::writeLog(__LINE__, 'processCatalog', 'canExec', $canExec);
			}
			if (!$canExec)
			{
				self::writeLog(__LINE__, 'processCatalog', 'canExec', $canExec);
				return md5(getmicrotime());
			}
		}
		$timeStart = getmicrotime();
		self::writeLog(__LINE__, 'processCatalog', 'step', self::$arSession["step"]);
		switch(self::$arSession["step"])
		{
			case 0:
				self::$arParams["start"] = getmicrotime();
				self::$arSession["pos"] = 0;
				self::$arSession["step"]++;
				break;
			case 1:
				$data = self::downloadData(self::$urlCollections);
				$continue = true;
				if ($continue)
				{
					$data = json_decode($data, true);
					if (is_array($data) && !empty($data))
					{
						self::$arSession["data"]["collections"] = $data;
					} else {
						self::$arSession = [];
						$continue = false;
					}
				}
				if ($continue)
				{
					$data = self::downloadData(self::$urlCatalog);
					$data = json_decode($data, true);
					if (is_array($data) && !empty($data))
					{
						self::$arSession["data"]["items"] = $data;
					} else {
						self::$arSession = [];
					}
				}
				if (self::$arSession["data"]["collections"] > 1 && self::$arSession["data"]["items"] > 1)
				{
					self::$arSession["step"]++;
				} else {
					self::$arSession["step"] = 9;
					if (self::$arSession["data"]["collections"] <= 1)
					{
						\CEvent::SendImmediate("SYNCH_ERROR", "s1", ["METHOD" => "get-collections"]);
					}
					elseif (self::$arSession["data"]["items"] <= 1)
					{
						\CEvent::SendImmediate("SYNCH_ERROR", "s1", ["METHOD" => "get-product-catalog"]);
					}
				}
				break;
			case 2:
				$cntStep = 0;
				$isContinue = Loader::includeModule("iblock");
				while ($isContinue)
				{
					if (self::$arSession["pos"] < count(self::$arSession["data"]["collections"]))
					{
						$arItem = self::$arSession["data"]["collections"][self::$arSession["pos"]];
						$arData = [
							"xmlid" => md5(implode("+", [
								"T:" . "COLLECTION",
								"A:" . $arItem["id"],
							])),
							"checksum" => hash(
								"crc32b",
								implode("+", [
									"M:" . implode(";", $arItem["divFk"]["id"]),
									"A:" . $arItem["flag_in_price"],
									"N:" . $arItem["name"],
									"C:" . $arItem["comment"],
									"E:" . $arItem["epithets"],
								])
							),
							"id" => 0,
							"update" => true,
						];
						$res = \CIBlockElement::GetList(
							["ID" => "ASC"],
							[
								"IBLOCK_ID" => self::$iblockCollectionId,
								"XML_ID" => $arData["xmlid"],
							],
							false,
							["nTopCount" => 1],
							[
								"ID",
								"ACTIVE",
								"PROPERTY_STATUS",
								"PROPERTY_CHECKSUM",
							]
						);
						if ($arRes = $res->GetNext())
						{
							$arData["id"] = $arRes["ID"];
							if ($arRes["PROPERTY_CHECKSUM_VALUE"] == $arData["checksum"])
							{
								$arData["update"] = false;
							}
						}
						if ($arData["id"] <= 0 || $arData["update"])
						{
							$arFields = [
								"ACTIVE" => "Y",
								"XML_ID" => $arData["xmlid"],
								"IBLOCK_ID" => self::$iblockCollectionId,
								"IBLOCK_SECTION_ID" => self::getSectionId(self::$iblockCollectionId, [[
									"xmlid" => md5(implode("+", [
										"T:" . "MATRIX",
										"I:" . $arItem["divFk"]["id"],
									])),
									"name" => $arItem["divFk"]["name"],
									"sort" => (10000 + ($arItem["divFk"]["sort"] * 100)),
								]]),
								"NAME" => trim($arItem["name"]),
								"CODE" => self::getCharacterCode(
									"e",
									self::$iblockCollectionId,
									str_replace("+", " plus ", trim($arItem["name"]))
								),
								"PREVIEW_TEXT" => trim($arItem["comment"]),
								"DETAIL_TEXT" => trim($arItem["epithets"]),
								"PROPERTY_VALUES" => [
									"IN_STOCK" => $arItem["flag_in_price"]
										? self::getPropertyEnumId(
											self::$iblockCollectionId,
											"IN_STOCK",
											"Y"
										)
										: false,
									"STATUS" => "Y",
									"ID" => intval($arItem["id"]),
									"CHECKSUM" => $arData["checksum"],
								]
							];
							$elm = new \CIBlockElement;
							$upd = false;
							if ($arData["id"] > 0)
							{
								$isActive = ($arRes["PROPERTY_STATUS_VALUE"] == "Y" && $arItem["flag_in_price"]);
								$arFields["ACTIVE"] = $isActive ? "Y" : "N";
								$arProperties = $arFields["PROPERTY_VALUES"];
								unset(
									$arFields["IBLOCK_ID"],
									$arFields["XML_ID"],
									$arFields["PROPERTY_VALUES"]
								);
								$upd = $elm->Update($arData["id"], $arFields);
								\CIBlockElement::SetPropertyValuesEx(
									$arData["id"],
									self::$iblockCollectionId,
									$arProperties
								);
							} else {
								$upd = $elm->Add($arFields);
							}
							unset($elm);
						} else {
							if ($arData["id"] > 0)
							{
								$elm = new \CIBlockElement;
								$elm->Update($arData["id"], ["TIMESTAMP_X" => new Type\DateTime]);
								unset($elm);
							}
						}
					}
					self::$arSession["pos"]++;
					$isFinished = (self::$arSession["pos"] >= count(self::$arSession["data"]["collections"]));
					$isExpired = ((getmicrotime() - $timeStart + 1) > self::$timeExec);
					$isCountLimit = (self::$cntLimitCollections > 0 && ++$cntStep >= self::$cntLimitCollections);
					if ($isExpired || $isFinished || $isCountLimit) {
						$isContinue = false;
						if ($isFinished) {
							self::$arSession["step"]++;
							self::$arSession["pos"] = 0;
						}
					}
				}
				break;
			case 3:
				$cntStep = 0;
				$isContinue = Loader::includeModule("iblock");
				while ($isContinue)
				{
					if (self::$arSession["pos"] < count(self::$arSession["data"]["items"]))
					{
						$arItem = self::$arSession["data"]["items"][self::$arSession["pos"]];
						foreach ($arItem as &$v)
						{
							if (is_string($v))
							{
								$v = trim($v);
							}
						}
						$skuStatus = "N";
						if ($arItem["flagOutputInB2C"] === true || $arItem["flagOutputInB2C"] === "true")
						{
							$skuStatus = "Y";
						}
						$arData = [
							"element" => [
								"xmlid" => md5(implode("+", [
									"T:" . "ELEMENT",
									"A:" . $arItem["article"],
								])),
								"checksum" => hash(
									"crc32b",
									implode("+", [
										"MID:" . $arItem["modelId"],
										"FID:" . $arItem["fabricId"],
										"FTX:" . $arItem["fabric"],
										"PRT:" . self::getPropertyRefId(self::$iblockSkuId, "PRINT", $arItem["print"]),
										"CLR:" . $arItem["color"],
										"TID:" . $arItem["themeId"],
										"TPH:" . $arItem["themePhoto"],
										"CID:" . $arItem["collectionId"],
										"FCL:" . implode(";", $arItem["fabricCare"]),
										"DCR:" . $arItem["prodDescription"],
										"AST:" . $arItem["assortment"],
										"PHT:" . implode(";", $arItem["photos"]["large"]),
										"WWI:" . implode(";", $arItem["wearWithIt"]),
									])
								),
								"id" => 0,
								"update" => true,
							],
							"sku" => [
								"xmlid" => md5(implode("+", [
									"T:" . "SKU",
									"I:" . $arItem["id"],
								])),
								"checksum" => hash(
									"crc32b",
									implode("+", [
										"EAN:" . $arItem["ean13"],
										"SZE:" . $arItem["size"],
										"FIP:" . $arItem["flagInPrice"],
										"PRC:" . $arItem["price"],
										"WGT:" . $arItem["weight"],
										"PCK:" . implode("x", $arItem["packSizes"]),
										"DCT:" . $arItem["discount"],
										"STS:" . $skuStatus,
									])
								),
								"id" => 0,
								"parent" => 0,
								"update" => true,
							],
						];
						$res = \CIBlockElement::GetList(
							["ID" => "ASC"],
							[
								"IBLOCK_ID" => self::$iblockSkuId,
								"XML_ID" => $arData["sku"]["xmlid"]],
							false,
							["nTopCount" => 1],
							[
								"ID",
								"PROPERTY_CML2_LINK",
								"PROPERTY_STATUS",
								"PROPERTY_CHECKSUM",
							]
						);
						if ($arRes = $res->GetNext())
						{
							$arData["sku"]["id"] = $arRes["ID"];
							$arData["sku"]["parent"] = intval($arRes["PROPERTY_CML2_LINK_VALUE"]);
							if ($arRes["PROPERTY_CHECKSUM_VALUE"] == $arData["sku"]["checksum"])
							{
								$arData["sku"]["update"] = false;
							}
						}
						if ($arData["sku"]["parent"] > 0)
						{
							$res = \CIBlockElement::GetList(
								["ID" => "ASC"],
								[
									"IBLOCK_ID" => self::$iblockCatalogId,
									"ID" => $arData["sku"]["parent"],
								],
								false,
								["nTopCount" => 1],
								[
									"ID",
									"PROPERTY_CHECKSUM",
								]
							);
							if ($arRes = $res->fetch())
							{
								$arData["element"]["id"] = intval($arRes["ID"]);
								if ($arRes["PROPERTY_CHECKSUM_VALUE"] == $arData["element"]["checksum"])
								{
									$arData["element"]["update"] = false;
								}
							} else {
								$arData["element"]["id"] = 0;
								$arData["sku"]["parent"] = 0;
							}
						}
						if ($arData["element"]["id"] == 0)
						{
							$res = \CIBlockElement::GetList(
								["ID" => "ASC"],
								[
									"IBLOCK_ID" => self::$iblockCatalogId,
									"XML_ID" => $arData["element"]["xmlid"],
								],
								false,
								["nTopCount" => 1],
								[
									"ID",
									"PROPERTY_CHECKSUM",
								]
							);
							if ($arRes = $res->GetNext())
							{
								$arData["element"]["id"] = intval($arRes["ID"]);
								if ($arRes["PROPERTY_CHECKSUM_VALUE"] == $arData["element"]["checksum"])
								{
									$arData["element"]["update"] = false;
								}
							}
						}
						if ($arData["element"]["id"] <= 0 || $arData["element"]["update"])
						{
							$arItem["name"] = strlen($arItem["name"]) ? $arItem["name"] : $arItem["modelProdName"];
							$arItem["name"] = strlen($arItem["name"]) ? $arItem["name"] : $arItem["class"];
							$arFields = [
								"ACTIVE" => "Y",
								"XML_ID" => $arData["element"]["xmlid"],
								"IBLOCK_ID" => self::$iblockCatalogId,
								"IBLOCK_SECTION_ID" => (intval($arItem["collectionId"]) > 0)
									? self::getSectionId(
										self::$iblockCatalogId,
										[
											self::$sectionMatrix,
											$arItem["sex"],
											self::getCollectionSection($arItem["collectionId"]),
											$arItem["class"]
										]
									)
									: self::getSectionId(
										self::$iblockCatalogId,
										[
											self::$sectionDiscount,
											$arItem["sex"],
											$arItem["group"],
											$arItem["class"]
										]
									),
								"NAME" => trim($arItem["name"]),
								"CODE" => self::getCharacterCode(
									"e",
									self::$iblockCatalogId,
									trim($arItem["article"]),
									$arItem["modelProdName"]
								),
								"PREVIEW_TEXT" => trim($arItem["prodDescription"]),
								"PROPERTY_VALUES" => [
									"ARTICLE" => trim($arItem["article"]),
									"ASSORTMENT" => self::getPropertyEnumId(
										self::$iblockCatalogId,
										'ASSORTMENT',
										$arItem["assortment"]
									),
									"COLLECTION" => intval($arItem["collectionId"]) > 0
										? self::getElementId(
											self::$iblockCollectionId,
											md5(implode("+", [
												"T:" . "COLLECTION",
												"A:" . intval($arItem["collectionId"]),
											]))
										)
										: false,
									"COLOR" => self::getPropertyRefId(
										self::$iblockCatalogId,
										"COLOR",
										$arItem["color"]
									),
									"THEME" => self::getPropertyRefId(
										self::$iblockCatalogId,
										"THEME",
										$arItem["themeStr"],
										$arItem["themeDescript"],
										md5($arItem["themeId"]),
										$arItem["themePhoto"]
									),
									"THEME_PHOTO" => $arItem["themePhoto"],
									"PRINT" => self::getPropertyRefId(
										self::$iblockCatalogId,
										"PRINT",
										trim($arItem["print"])
									),
									"FABRIC" => false,
									"DENSITY" => intval($arItem["fabricDensity"]) > 0
										? intval($arItem["fabricDensity"])
										: false,
									"CARE" => false,
									"RELATED" => $arItem["wearWithIt"],
									"STATUS" => "Y",
									"NAME" => hash("crc32b", ToUpper(trim($arItem["name"]))),
									"SEX" => $arItem["sex"],
									"SEX_HASH" => hash("crc32b", ToUpper(trim($arItem["sex"]))),
									"GROUP" => $arItem["group"],
									"GROUP_HASH" => hash("crc32b", ToUpper(trim($arItem["group"]))),
									"CLASS" => $arItem["class"],
									"CLASS_HASH" => hash("crc32b", ToUpper(trim($arItem["class"]))),
									"COLLECTION_ID" => intval($arItem["collectionId"]),
									"MODEL_ID" => intval($arItem["modelId"]),
									"THEME_ID" => intval($arItem["themeId"]),
									"FABRIC_ID" => intval($arItem["fabricId"]),
									"SEARCH" => "",
									"CHECKSUM" => $arData["element"]["checksum"],
								],
							];
							if (strlen($arItem["fabric"]) > 0)
							{
								foreach (explode(";", $arItem["fabric"]) as $key => $value)
								{
									if (preg_match("/(.+?)(\d+)/", $value, $matches))
									{
										$matches[1] = preg_replace("/^s+/", "", trim($matches[1]));
										$arFields["PROPERTY_VALUES"]["FABRIC"]["n" . $key] = [
											"VALUE" => $matches[1],
											"DESCRIPTION" => $matches[2],
										];
									}
								}
							}
							if (is_array($arItem["fabricCare"]) && !empty($arItem["fabricCare"]))
							{
								foreach ($arItem["fabricCare"] as $key => $value)
								{
									if (strlen(trim) > 0)
									{
										$arFields["PROPERTY_VALUES"]["CARE"][] = self::getPropertyEnumId(self::$iblockCatalogId, 'CARE', $value);
									}
								}
							}
							if (is_array($arItem["photos"]["large"]) && !empty($arItem["photos"]["large"]))
							{
								foreach ($arItem["photos"]["large"] as $key => $value)
								{
									if (strlen(trim) > 0)
									{
										$arFields["PROPERTY_VALUES"]["PHOTOS"]["n" . $key] = trim($value);
									}
								}
							}
							$elm = new \CIBlockElement;
							if ($arData["element"]["id"] > 0)
							{
								$arProperties = $arFields["PROPERTY_VALUES"];
								unset(
									$arFields["ACTIVE"],
									$arFields["IBLOCK_ID"],
									$arFields["XML_ID"],
									$arFields["CODE"],
									$arFields["PROPERTY_VALUES"],
									$arProperties["SEARCH"],
								);
								$upd = $elm->Update($arData["element"]["id"], $arFields);
								\CIBlockElement::SetPropertyValuesEx(
									$arData["element"]["id"],
									self::$iblockCatalogId,
									$arProperties
								);
							} else {
								$upd = $elm->Add($arFields);
								if (intval($upd) > 0)
								{
									$arData["element"]["id"] = intval($upd);
									self::getProductId("e", $upd);
								}
							}
							unset($elm);
						}
						if ($arData["element"]["id"] > 0)
						{
							$arData["sku"]["parent"] = $arData["element"]["id"];
						}
						if ($arData["sku"]["id"] <= 0 || $arData["sku"]["update"])
						{
							if ($arItem["flagInPrice"] === "true" || $arItem["flagInPrice"] === true)
							{
								$arItem["flagInPrice"] = 1;
							}
							$arFields = [
								"ACTIVE" => (intval($arItem["flagInPrice"]) == 1 && floatval($arItem["price"]) > 0 && $skuStatus == "Y")
									? "Y"
									: "N",
								"XML_ID" => $arData["sku"]["xmlid"],
								"IBLOCK_ID" => self::$iblockSkuId,
								"NAME" => trim($arItem["name"]),
								"PROPERTY_VALUES" => [
									"CML2_LINK" => $arData["sku"]["parent"],
									"EAN13" => $arItem["ean13"],
									"SIZE" => self::getPropertyEnumId(
										self::$iblockSkuId,
										"SIZE",
										$arItem["size"]
									),
									"ARTICLE" => $arItem["article"],
									"PRICE" => floatval($arItem["price"]) * self::$priceCoefficient,
									"DISCOUNT" => intval($arItem["discount"]),
									"STATUS" => $skuStatus,
									"CHECKSUM" => $arData["sku"]["checksum"],
								],
							];
							$elm = new \CIBlockElement;
							if ($arData["sku"]["id"] > 0)
							{
								$arProperties = $arFields["PROPERTY_VALUES"];
								unset(
									$arFields["ACTIVE"],
									$arFields["IBLOCK_ID"],
									$arFields["XML_ID"],
									$arFields["PROPERTY_VALUES"],
								);
								$upd = $elm->Update($arData["sku"]["id"], $arFields);
								\CIBlockElement::SetPropertyValuesEx(
									$arData["sku"]["id"],
									self::$iblockSkuId,
									$arProperties
								);
							} else {
								$upd = $elm->Add($arFields);
								if (intval($upd) > 0)
								{
									$arData["sku"]["id"] = intval($upd);
									self::getProductId("s", $upd);
								}
							}
							// Обновление цены, веса, габаритов
							if ($arData["sku"]["id"] > 0)
							{
								$skuPrice = $arItem["price"] * self::$priceCoefficient;
								if (intval($arItem["discount"]) > 0)
								{
									$skuPrice = $skuPrice * (1 - (intval($arItem["discount"]) / 100));
								}
								self::setProductPrice(
									"s",
									$arData["sku"]["id"],
									$skuPrice
								);
								self::setProductWeight(
									"s",
									$arData["sku"]["id"],
									($arItem["weight"] > 0 ? $arItem["weight"] : self::$defaultWeight)
								);
								self::setProductDimensions(
									"s",
									$arData["sku"]["id"],
									(
									$arItem["packSizes"]["width"] > 0
										? $arItem["packSizes"]["width"]
										: self::$defaultWidth
									),
									(
									$arItem["packSizes"]["length"] > 0
										? $arItem["packSizes"]["length"]
										: self::$defaultLength
									),
									(
									$arItem["packSizes"]["height"] > 0
										? $arItem["packSizes"]["height"]
										: self::$defaultHeight
									)
								);
							}
							unset($elm);
						}
						if ($arData["element"]["id"] > 0)
						{
							$elm = new \CIBlockElement;
							$elm->Update($arData["element"]["id"], ["TIMESTAMP_X" => new Type\DateTime]);
							unset($elm);
						}
						if ($arData["sku"]["id"] > 0)
						{
							$elm = new \CIBlockElement;
							$elm->Update($arData["sku"]["id"], ["TIMESTAMP_X" => new Type\DateTime]);
							unset($elm);
						}
					}
					self::$arSession["pos"]++;
					$isFinished = (self::$arSession["pos"] >= count(self::$arSession["data"]["items"]));
					$isExpired = ((getmicrotime() - $timeStart + 1) > self::$timeExec);
					$isCountLimit = (self::$cntLimitCatalog > 0 && ++$cntStep >= self::$cntLimitCatalog);
					if ($isExpired || $isFinished || $isCountLimit)
					{
						$isContinue = false;
						if ($isFinished)
						{
							self::$arSession["step"]++;
							self::$arSession["pos"] = 0;
						}
					}
				}
				break;
			case 4:
				$isContinue = Loader::includeModule("iblock");
				while ($isContinue)
				{
					$res = \CIBlockElement::GetList(
						["ID" => "ASC"],
						[
							"LOGIC" => "OR",
							[
								"IBLOCK_ID" => [self::$iblockCatalogId, self::$iblockSkuId],
								"ACTIVE" => "Y",
								"<TIMESTAMP_X" =>  Type\DateTime::createFromTimestamp(self::$arParams["start"]),
							],
							[
								"IBLOCK_ID" => self::$iblockCatalogId,
								"ACTIVE" => "Y",
								"PROPERTY_PHOTOS" => false,
							],
							[
								"IBLOCK_ID" => self::$iblockSkuId,
								"ACTIVE" => "Y",
								"PRICE" => 0,
							],
							[
								"IBLOCK_ID" => self::$iblockSkuId,
								"ACTIVE" => "Y",
								"=PROPERTY_STATUS" => "N",
							],
							[
								"IBLOCK_ID" => self::$iblockSkuId,
								"ACTIVE" => "Y",
								">=PROPERTY_DISCOUNT" => 25,
							],
						],
						false,
						["nTopCount" => 1],
						["ID"]
					);
					if ($arRes = $res->GetNext())
					{
						$elm = new \CIBlockElement;
						$elm->Update($arRes["ID"], ["ACTIVE" => "N"]);
						$isExpired = ((getmicrotime() - $timeStart + 1) > self::$timeExec);
						if ($isExpired)
						{
							$isContinue = false;
						}
					} else {
						$isContinue = false;
						self::$arSession["step"]++;
						self::$arSession["pos"] = 0;
					}
				}
				break;
			case 5:
				$isContinue = Loader::includeModule("iblock");
				while ($isContinue)
				{
					$res = \CIBlockElement::GetList(
						["ID" => "ASC"],
						[
							"IBLOCK_ID" => self::$iblockCatalogId,
							"SECTION_ACTIVE" => "Y",
							"SECTION_GLOBAL_ACTIVE" => "Y",
							">ID" => self::$arSession["pos"],
						],
						false,
						["nTopCount" => 1],
						["ID", "ACTIVE", "PROPERTY_STATUS", "PROPERTY_PHOTOS"]
					);
					if ($arRes = $res->GetNext())
					{
						self::$arSession["pos"] = $arRes["ID"];
						$status = "N";
						$sku = \CIBlockElement::GetList(
							["ID" => "ASC"],
							[
								"IBLOCK_ID" => self::$iblockSkuId,
								"ACTIVE" => "Y",
								"AVAILABLE" => "Y",
								">PRICE" => 0,
								"PROPERTY_CML2_LINK" => $arRes["ID"],
								"=PROPERTY_STATUS" => "Y",
							],
							false,
							["nTopCount" => 1],
							["ID"]
						);
						if ($arSku = $sku->GetNext())
						{
							$pv = strlen(trim($arRes["PROPERTY_PHOTOS_VALUE"]));
							if ($pv > 0)
							{
								$status = $arRes["PROPERTY_STATUS_VALUE"] != "N" ? "Y" : "N";
							}
						}
						$elm = new \CIBlockElement;
						$elm->Update($arRes["ID"], ["ACTIVE" => $status]);
						unset($elm);
						$isExpired = ((getmicrotime() - $timeStart + 1) > self::$timeExec);
						if ($isExpired)
						{
							$isContinue = false;
						}
					} else {
						$isContinue = false;
						self::$arSession["step"]++;
						self::$arSession["pos"] = 0;
					}
				}
				break;
			case 6:
				$isContinue = Loader::includeModule("iblock");
				$arProps = [
					"EAN13",
					"SIZE",
				];
				while ($isContinue)
				{
					$arResProps = [];
					$res = ElementTable::getList([
						"select" => ["ID"],
						"filter" => [
							"IBLOCK_ID" => self::$iblockCatalogId,
							"ACTIVE" => "Y",
							">ID" => self::$arSession["pos"],
						],
						"order" => ["ID" => "asc"],
						"limit" => 1,
					]);
					if ($arRes = $res->fetch())
					{
						self::$arSession["pos"] = $arRes["ID"];
						$sku = \CIBlockElement::GetList(
							["ID" => "ASC"],
							[
								"IBLOCK_ID" => self::$iblockSkuId,
								"ACTIVE" => "Y",
								"PROPERTY_CML2_LINK" => $arRes["ID"],
							],
							false,
							["nTopCount" => 50]
						);
						while ($rsSku = $sku->GetNextElement())
						{
							$arSku = $rsSku->GetProperties();
							foreach ($arSku as $k => $v)
							{
								if (in_array($k, $arProps))
								{
									switch ($v["USER_TYPE"])
									{
										case "directory":
											$arResProps[$k]["NAME"] = $v["NAME"];
											if (!is_array($v["VALUE"]))
											{
												$v["VALUE"] = [$v["VALUE"]];
											}
											foreach($v["VALUE"] as $hv)
											{
												if (strlen(trim($hv)) > 0)
												{
													$hv = self::getPropertyRefVal(self::$iblockSkuId, $k, $hv);
													if (strlen($hv) > 0)
													{
														$arResProps[$k]["VALUE"][] = $hv;
													}
												}
											}
											break;
										default:
											switch ($v["PROPERTY_TYPE"])
											{
												case "S":
													$arResProps[$k]["NAME"] = $v["NAME"];
													if (is_array($v["VALUE"]))
													{
														$arResProps[$k]["VALUE"] = array_merge($arResProps[$k]["VALUE"], $v["VALUE"]);
													} else {
														$arResProps[$k]["VALUE"][] = $v["VALUE"];
													}
													break;
												case "L":
													$arResProps[$k]["NAME"] = $v["NAME"];
													if (is_array($v["VALUE"]))
													{
														$arResProps[$k]["VALUE"] = array_merge($arResProps[$k]["VALUE"], $v["VALUE_ENUM"]);
													} else {
														$arResProps[$k]["VALUE"][] = $v["VALUE_ENUM"];
													}
													break;
											}
									}
								}
							}
						}
						$arContent = [];
						foreach ($arResProps as $k => $v)
						{
							if (!empty($v["VALUE"]))
							{
								$v["VALUE"] = array_diff(array_unique($v["VALUE"]), [""]);
							}
							if (!empty($v["VALUE"]))
							{
								$arContent[] = implode(
									": ",
									[
										$v["NAME"],
										implode(", ", $v["VALUE"])
									]
								);
							}
						}
						if (!empty($arContent))
						{
							\CIBlockElement::SetPropertyValuesEx(
								$arRes["ID"],
								$arRes["IBLOCK_ID"],
								["SEARCH" => implode("; ", $arContent)]
							);
							$elm = new \CIBlockElement;
							$elm->UpdateSearch($arRes["ID"], true);
							unset($elm);
						}
						$isExpired = ((getmicrotime() - $timeStart + 1) > self::$timeExec);
						if ($isExpired)
						{
							$isContinue = false;
						}
					} else {
						$isContinue = false;
						self::$arSession["step"]++;
						self::$arSession["pos"] = 0;
					}
				}
				break;
			case 7:
				$isContinue = Loader::includeModule("iblock");
				while ($isContinue)
				{
					$res = \CIBlockElement::GetList(
						["ID" => "ASC"],
						[
							"IBLOCK_ID" => self::$iblockCatalogId,
							">ID" => self::$arSession["pos"],
						],
						false,
						["nTopCount" => 1],
						["ID", "IBLOCK_ID", "PROPERTY_SEX", "PROPERTY_GROUP", "PROPERTY_CLASS", "PROPERTY_COLLECTION_ID"]
					);
					if ($arRes = $res->GetNext())
					{
						$eid = intval($arRes["ID"]);
						$sex = trim($arRes["PROPERTY_SEX_VALUE"]);
						$group = trim($arRes["PROPERTY_GROUP_VALUE"]);
						$class = trim($arRes["PROPERTY_CLASS_VALUE"]);
						$arSections = [];
						self::$arSession["pos"] = $eid;
						if (intval($arRes["PROPERTY_COLLECTION_ID_VALUE"]) > 0)
						{
							$arSections[] = self::getSectionId(
								self::$iblockCatalogId,
								[
									self::$sectionMatrix,
									$sex,
									self::getCollectionSection(intval($arRes["PROPERTY_COLLECTION_ID_VALUE"])),
									$class,
								]
							);
						}
						$arDiscount = [];
						$res = \CIBlockElement::GetList(
							["ID" => "ASC"],
							[
								"IBLOCK_ID" => self::$iblockSkuId,
								"ACTIVE" => "Y",
								"PROPERTY_CML2_LINK" => $eid,
							],
							false,
							["nTopCount" => 50],
							["ID", "PROPERTY_DISCOUNT"]
						);
						while ($arRes = $res->GetNext())
						{
							$arRes["DISCOUNT"] = intval($arRes["PROPERTY_DISCOUNT_VALUE"]);
							if ($arRes["DISCOUNT"] > 0 && !in_array($arRes["DISCOUNT"], $arDiscount))
							{
								$arDiscount[] = $arRes["DISCOUNT"];
							}
						}
						if (empty($arDiscount) && empty($arSections))
						{
							$arDiscount[] = 0;
						}
						if (!empty($arDiscount))
						{
							natsort($arDiscount);
							foreach ($arDiscount as $discount)
							{
								$arSections[] = self::getSectionId(
									self::$iblockCatalogId,
									[
										self::$sectionDiscount,
										$sex,
										intval($arRes["PROPERTY_COLLECTION_ID_VALUE"]) > 0
											? self::getCollectionSection(intval($arRes["PROPERTY_COLLECTION_ID_VALUE"]))
											: $group,
										$class
									]
								);
							}
						}
						if (empty($arSections))
						{
							$arSections[] =  self::getSectionId(
								self::$iblockCatalogId,
								[
									self::$sectionUndefined,
								]
							);
						}
						\CIBlockElement::SetElementSection($eid, $arSections, false, 0, reset($arSections));
						$isExpired = ((getmicrotime() - $timeStart + 1) > self::$timeExec);
						if ($isExpired)
						{
							$isContinue = false;
						}
					} else {
						$isContinue = false;
						self::$arSession["step"]++;
						self::$arSession["pos"] = 0;
					}
				}
				break;
			case 8:
				$isContinue = Loader::includeModule("iblock");
				while($isContinue)
				{
					$res = \CIBlockElement::GetList(
						["ID" => "ASC"],
						[
							"IBLOCK_ID" => [self::$iblockCatalogId, self::$iblockSkuId],
							">ID" => self::$arSession["pos"],
						],
						false,
						["nTopCount" => 1],
						["ID", "IBLOCK_ID"]
					);
					if ($arRes = $res->GetNext())
					{
						self::$arSession["pos"] = $arRes["ID"];
						PropertyIndex\Manager::updateElementIndex($arRes["IBLOCK_ID"], $arRes["ID"]);
						$isExpired = ((getmicrotime() - $timeStart + 1) > self::$timeExec);
						if ($isExpired)
						{
							$isContinue = false;
						}
					} else {
						\CIBlock::clearIblockTagCache(self::$iblockCatalogId);
						\CIBlock::clearIblockTagCache(self::$iblockSkuId);
						$isContinue = false;
						self::$arSession["step"]++;
						self::$arSession["pos"] = 0;
					}
				}
				break;
			case 9:
				self::$arSession["step"] = 0;
				self::$arSession["pos"] = 0;
				self::$arParams["finish"] = getmicrotime();
				self::$session = md5(time());
				break;
		}
		self::$arParams["exec"] = getmicrotime();
		self::setSession();
		self::setParams();
		return self::$session;
	}

	private static function processStock($session = '')
	{
		//define("SYNCH_TYPE", "S");
		$GLOBALS["SYNCH_TYPE"] = "S";
		self::$session = $session;
		self::getParams();
		self::getSession();
		if ((getmicrotime() - (float)self::$arParams["finish"]) < self::$timeIntStock)
		{
			return md5(getmicrotime());
		} else {
			if (self::mainSynchExecuting())
			{
				return md5(getmicrotime());
			}
		}
		$timeStart = getmicrotime();
		switch(self::$arSession["step"])
		{
			case 0:
				self::$arParams["start"] = getmicrotime();
				self::$arSession["pos"] = 0;
				self::$arSession["step"]++;
				break;
			case 1:
				$data = self::downloadData(self::$urlStock);
				$data = json_decode($data, true);
				if (is_array($data) && !empty($data))
				{
					self::$arSession["data"]["items"] = $data;
				} else {
					self::$arSession = [];
				}
				if (self::$arSession["data"]["items"] > 1)
				{
					self::$arSession["step"]++;
				} else {
					self::$arSession["step"] = 4;
					\CEvent::SendImmediate("SYNCH_ERROR", "s1", ["METHOD" => "get-stor-rest"]);
				}
				break;
			case 2:
				$isContinue = Loader::includeModule("iblock");
				$cntStep = 0;
				while ($isContinue)
				{
					if (self::$arSession["pos"] < count(self::$arSession["data"]["items"]))
					{
						$arItem = self::$arSession["data"]["items"][self::$arSession["pos"]];
						$arData = [
							"xmlid" => md5(implode("+", [
								"T:" . "SKU",
								"I:" . $arItem["id"],
							])),
							"id" => 0,
							"rest" => intval($arItem["rest"]),
						];
						$res = ElementTable::getList([
							"select" => ["ID"],
							"filter" => ["XML_ID" => $arData["xmlid"]],
							"order" => ["ID" => "asc"],
						]);
						if ($arRes = $res->fetch())
						{
							self::setProductRest($arRes["ID"], $arData["rest"]);
						}
					}
					self::$arSession["pos"]++;
					$isFinished = (self::$arSession["pos"] >= count(self::$arSession["data"]["items"]));
					$isExpired = ((getmicrotime() - $timeStart + 1) > self::$timeExec);
					$isCountLimit = (self::$cntLimitStock > 0 && ++$cntStep >= self::$cntLimitStock);
					if ($isExpired || $isFinished || $isCountLimit)
					{
						$isContinue = false;
						if ($isFinished)
						{
							self::$arSession["step"]++;
							self::$arSession["pos"] = 0;
						}
					}
				}
				break;
			case 3:
				$isContinue = true;
				while($isContinue)
				{
					$res = \CIBlockElement::GetList(
						["ID" => "ASC"],
						[
							"IBLOCK_ID" => [self::$iblockCatalogId, self::$iblockSkuId],
							">ID" => self::$arSession["pos"],
						],
						false,
						["nTopCount" => 1],
						["ID", "IBLOCK_ID"]
					);
					if ($arRes = $res->GetNext())
					{
						self::$arSession["pos"] = $arRes["ID"];
						PropertyIndex\Manager::updateElementIndex($arRes["IBLOCK_ID"], $arRes["ID"]);
						$isExpired = ((getmicrotime() - $timeStart + 1) > self::$timeExec);
						if ($isExpired)
						{
							$isContinue = false;
						}
					} else {
						\CIBlock::clearIblockTagCache(self::$iblockCatalogId);
						\CIBlock::clearIblockTagCache(self::$iblockSkuId);
						$isContinue = false;
						self::$arSession["step"]++;
						self::$arSession["pos"] = 0;
						// self::$arParams["finish"] = getmicrotime();
					}
				}
				break;
			case 4:
				self::$arSession["step"] = 0;
				self::$arSession["pos"] = 0;
				self::$arParams["finish"] = getmicrotime();
				self::$session = md5(time());
				break;
		}
		self::$arParams["exec"] = getmicrotime();
		self::setSession();
		self::setParams();
		return self::$session;
	}

	public static function ExecCatalog($session = '')
	{
		self::writeLog(__LINE__, 'ExecCatalog', 'START', 'TRUE');
		$result = self::processCatalog($session);
		self::writeLog(__LINE__, 'ExecCatalog', 'session', $session);
		self::writeLog(__LINE__, 'ExecCatalog', 'result', $result);
		self::writeLog(__LINE__, 'ExecCatalog', 'END', 'TRUE');
		return "\\".__METHOD__."('".$result."');";
	}

	public static function ExecStock($session = '')
	{
		self::writeLog(__LINE__, 'ExecStock', 'START', 'TRUE');
		$result = self::processStock($session);
		self::writeLog(__LINE__, 'ExecStock', 'session', $session);
		self::writeLog(__LINE__, 'ExecStock', 'result', $result);
		self::writeLog(__LINE__, 'ExecStock', 'END', 'TRUE');
		return "\\".__METHOD__."('".$result."');";
	}
}
?>
