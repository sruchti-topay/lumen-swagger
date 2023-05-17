<?php

namespace RonasIT\Support\AutoDoc\Drivers;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use RonasIT\Support\AutoDoc\Exceptions\MissedProductionFilePathException;
use RonasIT\Support\AutoDoc\Interfaces\SwaggerDriverInterface;
use stdClass;

class LocalDriver implements SwaggerDriverInterface
{
    public $prodFilePath;

    protected static $data;

    public function __construct()
    {
        $this->prodFilePath = config('auto-doc.drivers.local.production_path');

        if (empty($this->prodFilePath)) {
            throw new MissedProductionFilePathException();
        }
    }

    public function saveTmpData($tempData)
    {
        self::$data = $tempData;
    }

    public function getTmpData()
    {
        return self::$data;
    }

	public function convert($data)
	{
	   if (is_string($data)) {
		  return mb_convert_encoding($data, 'UTF-8');
	   } elseif (is_array($data)) {
		  $result = [];
		  foreach ($data as $key => $value) $result[$key] = self::convert($value);
		  return $result;
	   } elseif (is_object($data)) {
		  foreach ($data as $key => $value) $data->$key = self::convert($value);
		  return $data;
	   } else {
		  return $data;
	   }
	}

    public function saveData()
    {
		$data = $this->convert(self::$data);

        $content = json_encode($data, \JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if($content === false) {
			$content = json_last_error_msg();
		}
        file_put_contents($this->prodFilePath, $content);

        self::$data = [];
    }

    public function getDocumentation(): array
    {
        if (!file_exists($this->prodFilePath)) {
            throw new FileNotFoundException();
        }

        $fileContent = file_get_contents($this->prodFilePath);

        return json_decode($fileContent, true);
    }
}
