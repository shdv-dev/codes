<?php
define("STOP_STATISTICS", true);
define("PUBLIC_AJAX_MODE", true);
define("NO_KEEP_STATISTIC", "Y");
define("NO_AGENT_STATISTIC","Y");
define("DisableEventsCheck", true);

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Application,
	Bitrix\Main\Config\Option,
	Bitrix\Main\IO\Directory,
	Bitrix\Main\IO\File;

$tmpDir = "/tmp/upload/";
$maxDeleteStep = 60;
$lifetimeFile = 60*60*24;

$dirPath = array_diff(
	explode(
		"/", 
		implode(
			"/",
			[
				Application::getDocumentRoot(),
				Option::get("main", "upload_dir", "upload"),
				$tmpDir,
			]
		)
	),
	["", " "]
);
array_push($dirPath, "");
array_unshift($dirPath, "");
$dirPath = implode("/", $dirPath);

$dir = new Directory($dirPath, SITE_ID);

$deletedCnt = 0;
if ($dir->isExists())
{
	$arDirFile  = $dir->getChildren();
	foreach ($arDirFile as $item)
	{
		if ($item->isFile())
		{
			$item->delete();
			$deletedCnt++;
		} else {
			$filePath = str_replace("//", "/", $item->getPath()."/data.log");
			$isDirDelete = true;
			$file = new File($filePath);
			if ($file->isExists())
			{
				$data = unserialize($file->getContents());
				if ($data)
				{
					if ((time() - $data["file"]["created"]) < $lifetimeFile)
					{
						$isDirDelete = false;
					}
				}
			}
			if (!$isDirDelete)
			{
				$filePath = str_replace("//", "/", $item->getPath()."/content.dta");
				$file = new File($filePath);
				if ($file->isExists())
				{
					if ($file->getSize() == 0)
					{
						$isDirDelete = true;
					}
				}
			}
			if ($isDirDelete)
			{
				$item->delete();
			}
		}
	} 
}

//$fileHash = md5(implode(":", [session_id(), time()]);

$request = Application::getInstance()->getContext()->getRequest();
$request->getFileList();

if ($request->getPost("ajaxPost") == "y")
{
	$arResult = [];
	if ($request->getPost("base64Encode") == "y")
	{
		if ($request->getPost("fileData"))
		{
			$arFile = [
				"field" => $request->getPost("fileField"),
				"info" => $request->getPost("fileInfo"),
				"data" => $request->getPost("fileData"),
			];
			if (substr($arFile["data"], 0, 4) == "data" && strpos($arFile["data"], ",") !== false)
			{
				$arFile["data"] = substr($arFile["data"], strpos($arFile["data"], ","));
			}
			$uid = strlen($arFile["info"]["uid"]) ? $arFile["info"]["uid"] : getGuid();
			$tmpName = md5(implode(
				":",
				[
					time(),
					session_id(),
					randString(7),
					$arFile["field"],
					implode("+", $arFile["info"]),
				]
			));
			$tmpName = implode(
				"_",
				[
					randString(5, "abcdefghijklnmopqrstuvwxyz"),
					randString(5, "0123456789"),
					crc32($tmpName),
				]
			);
			$originalFile = str_replace("//", "/", $dirPath."/".$tmpName."/content.dta");
			File::putFileContents($originalFile, base64_decode($arFile["data"]), File::REWRITE);
			unset($arFile["data"]);
			if (substr($arFile["field"], -2) == "[]")
			{
				$arFile["field"] = [
					"name" => substr($arFile["field"], 0, strlen($arFile["field"]) - 2),
					"multiple" => true,
				];
			} else {
				$arFile["field"] = [
					"name" => $arFile["field"],
					"multiple" => false,
				];
			}
			$tmpFile = new File($originalFile);
			$arFile["info"]["size"] = $tmpFile->getSize();
			$arFile["info"]["created"] = $tmpFile->getCreationTime();
			$arFile["info"]["name"] = [
				"original" => $arFile["info"]["name"],
				"temporary" => $tmpName,
			];
			$arFile["file"] = $arFile["info"];
			unset($arFile["info"]);
			if (\CFile::IsImage($arFile["file"]["name"]["original"], $arFile["file"]["type"]))
			{
				$previewSize = $request->getPost("previewSize");
				$previewSize["width"] = $previewSize["width"] > 0 ? $previewSize["width"] : 0;
				$previewSize["height"] = $previewSize["height"] > 0 ? $previewSize["height"] : 0;
				if ($previewSize["width"] > 0 && $previewSize["height"] > 0)
				{
					$previewFile = str_replace(
						"//",
						"/",
						implode(
							"/",
							[
								$dirPath,
								$tmpName,
								implode(
									".",
									[
										md5($tmpName.time()),
										end(explode(".", $arFile["file"]["name"]["original"])),
									]
								),
							]
						)
					);
					\CFile::ResizeImageFile(
						$originalFile,
						$previewFile,
						$previewSize,
						BX_RESIZE_IMAGE_EXACT
					);
					$dr = Application::getDocumentRoot();
					if (substr($previewFile, 0, strlen($dr)) == $dr)
					{
						$previewFile = substr($previewFile, strlen($dr));
						if (substr($previewFile, 0, 1) !== "/")
						{
							$previewFile = "/".$previewFile;
						}
					}
					$arFile["preview"] = [
						"path" => $previewFile,
						"size" => $previewSize,
					];
				} 
			}
			$infoFile = str_replace("//", "/", $dirPath."/".$tmpName."/data.log");
			File::putFileContents($infoFile, serialize($arFile));
			$arResult[$uid] = $arFile;
		}
	}
	if ($request->getPost("remove") == "y" && $request->getPost("key"))
	{
		$uid = $request->getPost("uid");
		$uid = strlen($uid) ? $uid : getGuid();
		$fileDir = str_replace("//", "/", $dirPath."/".$request->getPost("key")."/");
		$isDeleted = false;
		if (Directory::isDirectoryExists($fileDir))
		{
			Directory::deleteDirectory($fileDir);
			$isDeleted = true;
		}
		$arResult[$uid] = ["deleted" => $isDeleted];
	}
	echo json_encode($arResult);
}
?>
