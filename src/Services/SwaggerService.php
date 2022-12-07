<?php

namespace RonasIT\Support\AutoDoc\Services;

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse as LumenJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LumenResponse;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionClass;
use RonasIT\Support\AutoDoc\Exceptions\InvalidDriverClassException;
use RonasIT\Support\AutoDoc\Exceptions\LegacyConfigException;
use RonasIT\Support\AutoDoc\Exceptions\SwaggerDriverClassNotFoundException;
use RonasIT\Support\AutoDoc\Exceptions\WrongSecurityConfigException;
use RonasIT\Support\AutoDoc\Interfaces\SwaggerDriverInterface;
use RonasIT\Support\AutoDoc\Traits\GetDependenciesTrait;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * @property SwaggerDriverInterface $driver
 */
class SwaggerService
{
    use GetDependenciesTrait;

    protected SwaggerDriverInterface $driver;

    protected array|null $data;
    protected array $config;
    protected Container $container;
    private string $uri;
    private string $method;
    private Request $request;
    /** @var array $item An pointer to the current item in the $data array */
    private array $item;
    private string $security;

    protected $ruleToTypeMap = [
        'array' => 'object',
        'boolean' => 'boolean',
        'date' => 'date',
        'digits' => 'integer',
        'integer' => 'integer',
        'numeric' => 'double',
        'string' => 'string'
    ];

    protected $typeConversionTable = [
        'array' => 'array',
        'boolean' => 'boolean',
        'double' => 'number',
        'integer' => 'integer',
        'string' => 'string',
		'NULL' => 'string',
		'object' => 'object'
    ];

	protected $defaultHeaders = [
		'host',
		'user-agent',
		'accept',
		'accept-language',
		'accept-charset',
		'authorization',
		'content-type',
		'content-length'
	];

    protected $documentation;

    public function __construct(Container $container)
    {
        $this->initConfig();

        $this->setDriver();

        if (env('AUTODOC_ENABLE', false)) {
            $this->container = $container;

            $this->security = $this->config['security'];

            $this->data = $this->driver->getTmpData();

            if (empty($this->data)) {
                $this->data = $this->generateEmptyData();

                $this->driver->saveTmpData($this->data);
            }
        }
    }

    protected function initConfig() : void
    {
        $this->config = config('auto-doc');

        $version = Arr::get($this->config, 'config_version');

        if (empty($version)) {
            throw new LegacyConfigException();
        }
        /** @var array $packageConfigs Contains the array with the default settings */
        $packageConfigs = require __DIR__ . '/../../config/auto-doc.php';

        $major = (int)Str::before($version, '.');
        $minor = (int)Str::after($version, '.');

        $actualMajor = (int)Str::before($packageConfigs['config_version'], '.');
        $actualMinor = (int)Str::after($packageConfigs['config_version'], '.');

        if ($actualMajor > $major || $actualMinor > $minor) {
            throw new LegacyConfigException();
        }
    }

    protected function setDriver() : void
    {
        /** @var string $driver Driver name as key to lookup in drivers var */
        $driver = $this->config['driver'];
        /** @var string $className String containing the classname to initiate */
        $className = Arr::get($this->config, "drivers.{$driver}.class");

        if (!class_exists($className)) {
            throw new SwaggerDriverClassNotFoundException($className);
        } else {
            $this->driver = app($className);
        }

        if (!$this->driver instanceof SwaggerDriverInterface) {
            throw new InvalidDriverClassException($driver);
        }
    }

    protected function generateEmptyData(): array
    {
        $data = [
            'openapi' => Arr::get($this->config, 'swagger.version'),
            'paths' => [],
            'servers' => Arr::get($this->config, 'servers'),
            'components' => [
                'schemas' => [],
                'securitySchemes' => [

                ]
            ],
            'tags' => [],
            'externalDocs' => [
                'url' => ''
            ]
        ];

        $info = $this->prepareInfo($this->config['info']);

        if (!empty($info)) {
            $data['info'] = $info;
        }

        $securityDefinitions = $this->generateSecurityDefinition();

        if (!empty($securityDefinitions)) {
            $data['components']['securitySchemes'] = $securityDefinitions;
        }

        $data['info']['description'] = view($data['info']['description'])->render();

        return $data;
    }

    protected function getAppUrl() : string
    {
        $url = config('app.url');

        return str_replace(['http://', 'https://', '/'], '', $url);
    }

    protected function generateSecurityDefinition() : string|array
    {
        if (empty($this->security)) {
            return '';
        }

        return [
            $this->security => $this->generateSecurityDefinitionObject($this->security)
        ];
    }

    protected function generateSecurityDefinitionObject(string $type): array
    {
        switch ($type) {
            case 'jwt':
                return [
                    'type' => 'apiKey',
                    'name' => 'Authorization',
                    'in' => 'header'
                ];

            case 'laravel':
                return [
                    'type' => 'apiKey',
                    'name' => 'Cookie',
                    'in' => 'header'
                ];
            default:
                throw new WrongSecurityConfigException();
        }
    }

    public function addData(Request $request, LumenResponse|LumenJsonResponse $response) : void
    {
        $this->request = $request;

        $this->prepareItem();

        $this->parseRequest();
        $this->parseResponse($response);

        $this->driver->saveTmpData($this->data);
    }

    protected function prepareItem() : void
    {
        $this->uri = "/{$this->getUri()}";
        $this->method = strtolower($this->request->getMethod());

        if (empty(Arr::get($this->data, "paths.{$this->uri}.{$this->method}"))) {
            $this->data['paths'][$this->uri][$this->method] = [
                'tags' => [],
                'parameters' => $this->getPathParams(),
                'responses' => [],
                'security' => [],
                'description' => '',
                'operationId' => ''
            ];
        }

        $this->item = &$this->data['paths'][$this->uri][$this->method]; // Pointer !!!!
    }

    protected function getUri() : string|array|null
    {
        $uri = $this->request->getUri();
        $basePath = preg_replace("/^\//", '', $this->config['basePath']);
        $preparedUri = preg_replace("/^{$basePath}/", '', $uri);
		$preparedUri = explode('?', $preparedUri)[0];

        return preg_replace("/^\//", '', $preparedUri);
    }

    protected function getPathParams(): array
    {
        $params = [];

        preg_match_all('/{.*?}/', $this->uri, $params);

        $params = Arr::collapse($params);

        $result = [];

        foreach ($params as $param) {
            $key = preg_replace('/[{}]/', '', $param);

            $result[] = [
                'in' => 'path',
                'name' => $key,
                'description' => '',
                'required' => true,
                'type' => 'string'
            ];
        }

        return $result;
    }

    protected function parseRequest() : void
    {
        $this->saveConsume();
        $this->saveTags();
        $this->saveSecurity();

        $concreteRequest = $this->getConcreteRequest();

        if (empty($concreteRequest)) {
            $this->item['description'] = '';

            return;
        }

        $annotations = $this->getClassAnnotations($concreteRequest);

        $this->saveParameters($concreteRequest, $annotations);
        $this->saveDescription($concreteRequest, $annotations);

        $this->saveOperationId();
    }

    protected function parseResponse(LumenResponse|LumenJsonResponse $response) : void
    {
        $code = $response->getStatusCode();

        if(!isset($this->item['responses'][$code])) {
            $this->item['responses'][$code] = [
                'description' => $this->getResponseDescription($code)
            ];
        }
        if(!isset($this->item['responses'][$code]['content'])) {
            $this->item['responses'][$code]['content'] = [];
        }
        $produceList = array_keys($this->data['paths'][$this->uri][$this->method]['responses'][$code]['content']);

        $produce = $response->headers->get('Content-type');

        if (is_null($produce)) {
            $produce = 'text/plain';
        }

        if (!in_array($produce, $produceList)) {
            $this->item['responses'][$code]['content'][$produce] = [];
        }

        $responses = $this->item['responses'];

        if (!isset($this->item['responses'][$code]['content'][$produce]['example'])) {
            $this->saveExample(
                $response->getStatusCode(),
                $response->getContent(),
                $produce
            );
        }
    }

    protected function saveExample(string $code, mixed $content, string $produce) : void
    {
        $availableContentTypes = [
            'application',
            'text',
			'image'
        ];
        $explodedContentType = explode('/', $produce);

        if (in_array($explodedContentType[0], $availableContentTypes)) {
            $this->item['responses'][$code]['content'][$produce] = $this->makeResponseExample($content, $produce);
        } else {
            $this->item['responses'][$code]['content'][$produce] = '*Unavailable for preview*';
        }
    }

    protected function makeResponseExample(mixed $content, string $mimeType): array
    {
        $responseExample = [];

        if ($mimeType === 'application/json') {
            $responseExample['example'] = ['value' => json_decode($content, true)];
        } elseif ($mimeType === 'application/pdf') {
            $responseExample['example'] = ['value' => base64_encode($content)];
        } elseif (str_contains($mimeType, 'image')) {
            $responseExample['example'] = ['value' => base64_encode($content)];
        }else {
            $responseExample['example'] = ['value' => $content];
        }

        return $responseExample;
    }

    protected function saveParameters($request, array $annotations)
    {
        $formRequest = new $request;
        $formRequest->setUserResolver($this->request->getUserResolver());
        $formRequest->setRouteResolver($this->request->getRouteResolver());
		$bodyRules = method_exists($formRequest, 'bodyRules') ? $formRequest->bodyRules() : [];
		$queryRules = method_exists($formRequest, 'queryRules') ? $formRequest->queryRules() : [];
        $attributes = method_exists($formRequest, 'attributes') ? $formRequest->attributes() : [];

        $actionName = $this->getActionName($this->uri);

        $this->saveGetRequestParameters($queryRules, $attributes, $annotations);
		$this->saveHeaderParameters();

        if (!in_array($this->method, ['get', 'delete'])) {
            $this->savePostRequestParameters($actionName, $bodyRules, $attributes, $annotations);
        }
    }

    protected function saveGetRequestParameters($rules, array $attributes, array $annotations)
    {
        foreach ($rules as $parameter => $rule) {
            $validation = explode('|', $rule);

            $description = Arr::get($annotations, $parameter);

            if (empty($description)) {
                $description = Arr::get($attributes, $parameter, implode(', ', $validation));
            }

            $existedParameter = Arr::first($this->item['parameters'], function ($existedParameter) use ($parameter) {
                return $existedParameter['name'] == $parameter;
            });

            if (empty($existedParameter)) {
                $parameterDefinition = [
                    'in' => 'query',
                    'name' => $parameter,
                    'description' => $description,
					'schema' => [
						'type' => $this->getParameterType($validation),
					]
                ];
                if (in_array('required', $validation)) {
                    $parameterDefinition['required'] = true;
                }
				if (in_array('present', $validation)) {
                    $parameterDefinition['required'] = true;
                }
				foreach ($validation as $case) {
					if (str_starts_with($case, 'in:')) {
						$parameterDefinition['schema']['enum'] = explode(',', substr($case, 3));
					}
				}

                $this->item['parameters'][] = $parameterDefinition;
            }
        }
    }

	protected function saveHeaderParameters() {
		$aDifferences = array_diff(array_keys($this->request->header()), $this->defaultHeaders);

		foreach ($aDifferences as $sDiff) {
            $existedParameter = Arr::first($this->item['parameters'], function ($existedParameter) use ($sDiff) {
                return $existedParameter['name'] == $sDiff;
            });
            if (empty($existedParameter)) {
                $this->item['parameters'][] = [
                    'name' => $sDiff,
                    'in' => 'header',
                    'required' => true,
                    'example' => $this->request->header($sDiff),
                    'schema' => [
                        'type' => 'string'
                    ]
                ];
            }
		}
	}

    protected function savePostRequestParameters($actionName, $rules, array $attributes, array $annotations)
    {
        if ($this->requestHasMoreProperties($actionName)) {
            if ($this->requestHasBody()) {
                $this->item['requestBody']['required'] = true;
                $this->item['requestBody']['description'] = '';
                $this->item['requestBody']['content'][array_key_first($this->item['requestBody']['content'])] = [
                    'schema' => [
                        "\$ref" => "#/components/schemas/{$actionName}RequestObject"
                    ]
                ];
            }

            $this->saveComponentSchemaRequestObject($actionName, $rules, $attributes, $annotations);
        }
    }

    protected function saveComponentSchemaRequestObject($objectName, $rules, $attributes, array $annotations)
    {
        $data = [
            'type' => 'object',
            'properties' => [],
        ];

        foreach ($rules as $parameter => $rule) {
            $rulesArray = (is_array($rule)) ? $rule : explode('|', $rule);
            $parameterType = $this->getParameterType($rulesArray);
            $this->saveParameterType($data, $parameter, $parameterType);

            $uselessRules = $this->ruleToTypeMap;
            $uselessRules['required'] = 'required';

            if (in_array('required', $rulesArray)) {
                $data['required'][] = $parameter;
            }

            foreach ($rulesArray as $key => $rule) {
				if (is_object($rule)) {
                    if(method_exists($rule, 'toSwagger')) {
                        $rulesArray[$key] = $rule->toSwagger();
                    } else {
                        unset($rulesArray[$key]);
                    }
				}
			}

            $rulesArray = array_flip(array_diff_key(array_flip($rulesArray), $uselessRules));

            $this->saveParameterDescription($data, $parameter, $rulesArray, $attributes, $annotations);
        }

        $data['properties'] = $this->getRequestParameters($this->request->all());

        $data['example'] = $this->generateExample($data['properties']);
        $this->data['components']['schemas'][$objectName . 'RequestObject'] = $data;
    }

    protected function getRequestParameters(array $parameters, array $properties = []) {
		foreach($parameters as $key => $value) {
			$sType = $this->typeConversionTable[gettype($value)];
			if($sType === 'array' && !array_is_list($value)) {
				$sType = 'object';
			}
			$properties[$key] = [
				'type' => $sType,
				'example' => $value ?? 'null',
				'nullable' => is_null($value) ? true : false
			];
		}
		return $properties;
	}

    protected function getParameterType(array $validation): string
    {
        $validationRules = $this->ruleToTypeMap;
        $parameterType = 'string';

        foreach ($validation as $item) {
            if (in_array($item, array_keys($validationRules))) {
                return $validationRules[$item];
            }
        }

        return $parameterType;
    }

    protected function saveParameterType(&$data, $parameter, $parameterType)
    {
        $data['properties'][$parameter] = [
            'type' => $parameterType
        ];
    }

    protected function saveParameterDescription(&$data, $parameter, array $rulesArray, array $attributes, array $annotations)
    {
        $description = Arr::get($annotations, $parameter);

        if (empty($description)) {
            $description = Arr::get($attributes, $parameter, implode(', ', $rulesArray));
        }

        $data['properties'][$parameter]['description'] = $description;
    }

    protected function requestHasMoreProperties($actionName): bool
    {
        $requestParametersCount = count($this->request->all());

        if (isset($this->data['components']['schemas'][$actionName . 'RequestObject']['properties'])) {
            $objectParametersCount = count($this->data['components']['schemas'][$actionName . 'RequestObject']['properties']);
        } else {
            $objectParametersCount = 0;
        }

        return $requestParametersCount > $objectParametersCount;
    }

    protected function requestHasBody(): bool
    {
        $parameters = $this->data['paths'][$this->uri][$this->method]['parameters'];

        $bodyParamExisted = Arr::where($parameters, function ($value) {
            return $value['name'] == 'body';
        });

        return empty($bodyParamExisted);
    }

    public function getConcreteRequest()
    {
        /** @var string $controller */
        $controller = $this->request->route()[1]['uses'];

        if ($controller == 'Closure') {
            return null;
        }

        $explodedController = explode('@', $controller);

        $class = $explodedController[0];
        $method = $explodedController[1];

        $instance = app($class);
        /** @var array $route */
        $route = $this->request->route();

        $parameters = $this->resolveClassMethodDependencies(
            \array_filter($route[2]), $instance, $method
        );

        return Arr::first($parameters, function ($key) {
            return preg_match('/Request/', $key);
        });
    }

    public function saveConsume() : void
    {
        if(!isset($this->item['requestBody'])) {
            $this->item['requestBody'] = [];
        }
        if(!isset($this->item['requestBody']['content'])) {
            $this->item['requestBody']['content'] = [];
        }
        $consumeList = array_keys($this->data['paths'][$this->uri][$this->method]['requestBody']['content']);
        $consume = $this->request->header('Content-Type');

        if (!empty($consume) && !in_array($consume, $consumeList)) {
            $this->item['requestBody']['content'][$consume] = new stdClass;
        } else {
            if(count($consumeList) < 1) {   // Nothing added yet
                unset($this->item['requestBody']);
            }
        }
    }

    public function saveTags() : void
    {
        $tagIndex = 1;

        $explodedUri = explode('/', $this->uri);

        $tag = Arr::get($explodedUri, $tagIndex);

        $this->item['tags'] = [$tag];
    }

    public function saveDescription($request, array $annotations) : void
    {
        $this->item['summary'] = $this->getSummary($request, $annotations);

        $description = Arr::get($annotations, 'description');

        if (!empty($description)) {
            $this->item['description'] = $description;
        }
    }

    public function saveOperationId() : void {
        $this->item['operationId'] = ucfirst($this->method).$this->uri;
        foreach($this->item['tags'] as $sTag) {
            $this->item['operationId'].=ucfirst($sTag);
        }
        foreach($this->item['parameters'] as $aParam) {
            $this->item['operationId'].=ucfirst($aParam['name']);
        }
    }

    protected function saveSecurity() : void
    {
        if ($this->requestSupportAuth()) {
            $this->addSecurityToOperation();
        }
    }

    protected function addSecurityToOperation() : void
    {
        $security = &$this->data['paths'][$this->uri][$this->method]['security'];

        if (empty($security)) {
            $security[] = [
                "{$this->security}" => []
            ];
        }
    }

    protected function getSummary($request, array $annotations)
    {
        $summary = Arr::get($annotations, 'summary');

        if (empty($summary)) {
            $summary = $this->parseRequestName($request);
        }

        return $summary;
    }

    protected function requestSupportAuth(): bool
    {
        switch ($this->security) {
            case 'jwt' :
                $header = $this->request->header('authorization');
                break;
            case 'laravel' :
                $header = $this->request->cookie('__ym_uid');
                break;
        }

        return !empty($header);
    }

    protected function parseRequestName(string $request) : string|array|null
    {
        $explodedRequest = explode('\\', $request);
        $requestName = array_pop($explodedRequest);
        $summaryName = str_replace('Request', '', $requestName);

        $underscoreRequestName = $this->camelCaseToUnderScore($summaryName);

        return preg_replace('/[_]/', ' ', $underscoreRequestName);
    }

    protected function getResponseDescription($code)
    {
        $defaultDescription = Response::$statusTexts[$code];

        $request = $this->getConcreteRequest();

        if (empty($request)) {
            return $defaultDescription;
        }

        $annotations = $this->getClassAnnotations($request);

        $localDescription = Arr::get($annotations, "_{$code}");

        if (!empty($localDescription)) {
            return $localDescription;
        }

        return Arr::get($this->config, "defaults.code-descriptions.{$code}", $defaultDescription);
    }

    protected function getActionName(string $uri): string
    {
        $action = preg_replace('[\/]', '', $uri);

        return Str::camel($action);
    }

    protected function saveTempData() : void
    {
        $exportFile = Arr::get($this->config, 'files.temporary');
        $data = json_encode($this->data);

        file_put_contents($exportFile, $data);
    }

    public function saveProductionData() : void
    {
        $this->driver->saveData();
    }

    public function getDocFileContent() : array
    {
        $this->documentation = $this->driver->getDocumentation();

        $additionalDocs = config('auto-doc.additional_paths', []);

        foreach ($additionalDocs as $filePath) {
            $fileContent = json_decode(file_get_contents($filePath), true);

            $paths = array_keys($fileContent['paths']);

            foreach ($paths as $path) {
                $additionalDocPath =  $fileContent['paths'][$path];

                if (empty($this->documentation['paths'][$path])) {
                    $this->documentation['paths'][$path] = $additionalDocPath;
                } else {
                    $methods = array_keys($this->documentation['paths'][$path]);
                    $additionalDocMethods = array_keys($additionalDocPath);

                    foreach ($additionalDocMethods as $method) {
                        if (!in_array($method, $methods)) {
                            $this->documentation['paths'][$path][$method] = $additionalDocPath[$method];
                        }
                    }
                }
            }

            $componentsSchemas = array_keys($fileContent['components']['schemas']);

            foreach ($componentsSchemas as $componentsSchema) {
                if (empty($this->documentation['components']['schemas'][$componentsSchema])) {
                    $this->documentation['components']['schemas'][$componentsSchema] = $fileContent['components']['schemas'][$componentsSchema];
                }
            }
        }

        return $this->documentation;
    }

    protected function camelCaseToUnderScore(string $input): string
    {
        preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
        $ret = $matches[0];

        foreach ($ret as &$match) {
            $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
        }

        return implode('_', $ret);
    }

    protected function generateExample($properties): array
    {
        $parameters = $this->replaceObjectValues($this->request->all());
        $example = [];

        $this->replaceNullValues($parameters, $properties, $example);

        return $example;
    }

    protected function replaceObjectValues(array $parameters): array
    {
        $classNamesValues = [
            File::class => '[uploaded_file]',
        ];

        $parameters = Arr::dot($parameters);
        $returnParameters = [];

        foreach ($parameters as $parameter => $value) {
            if (is_object($value)) {
				if ($value instanceof UploadedFile) {
					$value = ['value' => $value->path()];
				} else {
					$class = get_class($value);
					$value = Arr::get($classNamesValues, $class, $class);
				}
            }

            Arr::set($returnParameters, $parameter, $value);
        }

        return $returnParameters;
    }

    protected function getClassAnnotations(string $class): array
    {
        $reflection = new ReflectionClass($class);

        $annotations = $reflection->getDocComment();

        $blocks = explode("\n", $annotations);

        $result = [];

        foreach ($blocks as $block) {
            if (Str::contains($block, '@')) {
                $index = strpos($block, '@');
                $block = substr($block, $index);
                $exploded = explode(' ', $block);

                $paramName = str_replace('@', '', array_shift($exploded));
                $paramValue = implode(' ', $exploded);

                $result[$paramName] = $paramValue;
            }
        }

        return $result;
    }

    /**
     * NOTE: All functions below are temporary solution for
     * this issue: https://github.com/OAI/OpenAPI-Specification/issues/229
     * We hope swagger developers will resolve this problem in next release of Swagger OpenAPI
     * */
    protected function replaceNullValues(array $parameters, array $types, &$example)
    {
        foreach ($parameters as $parameter => $value) {
            if (is_null($value) && in_array($parameter, $types)) {
                $example[$parameter] = $this->getDefaultValueByType($types[$parameter]['type']);
            } elseif (is_array($value)) {
                $this->replaceNullValues($value, $types, $example[$parameter]);
            } else {
                $example[$parameter] = $value;
            }
        }
    }

    protected function getDefaultValueByType(string $type) : string|int|false
    {
        $values = [
            'object' => 'null',
            'boolean' => false,
            'date' => "0000-00-00",
            'integer' => 0,
            'string' => '',
            'double' => 0
        ];

        return $values[$type];
    }

    /**
     * @param $info
     * @return array
     */
    protected function prepareInfo(array $info) : array
    {
        if (empty($info)) {
            return $info;
        }

        foreach ($info['license'] as $key => $value) {
            if (empty($value)) {
                unset($info['license'][$key]);
            }
        }
        if (empty($info['license'])) {
            unset($info['license']);
        }

        return $info;
    }
}
