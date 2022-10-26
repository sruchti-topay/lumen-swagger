<?php

namespace RonasIT\Support\AutoDoc\Services;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionClass;
use RonasIT\Support\AutoDoc\Exceptions\InvalidDriverClassException;
use RonasIT\Support\AutoDoc\Exceptions\LegacyConfigException;
use RonasIT\Support\AutoDoc\Exceptions\SwaggerDriverClassNotFoundException;
use RonasIT\Support\AutoDoc\Exceptions\WrongSecurityConfigException;
use RonasIT\Support\AutoDoc\Interfaces\SwaggerDriverInterface;
use RonasIT\Support\AutoDoc\Traits\GetDependenciesTrait;
use Symfony\Component\HttpFoundation\Response;

/**
 * @property SwaggerDriverInterface $driver
 */
class SwaggerService
{
    use GetDependenciesTrait;

    protected $driver;

    protected $data;
    protected $config;
    protected $container;
    private $uri;
    private $method;
    /**
     * @var Request
     */
    private $request;
    private $item;
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

    protected function initConfig()
    {
        $this->config = config('auto-doc');

        $version = Arr::get($this->config, 'config_version');

        if (empty($version)) {
            throw new LegacyConfigException();
        }

        $packageConfigs = require __DIR__ . '/../../config/auto-doc.php';

        $major = Str::before($version, '.');
        $minor = Str::after($version, '.');

        $actualMajor = Str::before($packageConfigs['config_version'], '.');
        $actualMinor = Str::after($packageConfigs['config_version'], '.');

        if ($actualMajor > $major || $actualMinor > $minor) {
            throw new LegacyConfigException();
        }
    }

    protected function setDriver()
    {
        $driver = $this->config['driver'];
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
            'externalDocs' => []
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

    protected function getAppUrl()
    {
        $url = config('app.url');

        return str_replace(['http://', 'https://', '/'], '', $url);
    }

    protected function generateSecurityDefinition()
    {
        if (empty($this->security)) {
            return '';
        }

        return [
            $this->security => $this->generateSecurityDefinitionObject($this->security)
        ];
    }

    protected function generateSecurityDefinitionObject($type): array
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

    public function addData(Request $request, $response)
    {
        $this->request = $request;

        $this->prepareItem();

        $this->parseRequest();
        $this->parseResponse($response);

        $this->driver->saveTmpData($this->data);
    }

    protected function prepareItem()
    {
        $this->uri = "/{$this->getUri()}";
        $this->method = strtolower($this->request->getMethod());

        if (empty(Arr::get($this->data, "paths.{$this->uri}.{$this->method}"))) {
            $this->data['paths'][$this->uri][$this->method] = [
                'tags' => [],
                'produces' => [],
                'parameters' => $this->getPathParams(),
                'responses' => [],
                'security' => [],
                'description' => '',
                'operationId' => ''
            ];
        }

        $this->item = &$this->data['paths'][$this->uri][$this->method]; // Pointer !!!!
    }

    protected function getUri()
    {
        $uri = $this->request->getUri();
        $basePath = preg_replace("/^\//", '', $this->config['basePath']);
        $preparedUri = preg_replace("/^{$basePath}/", '', $uri);

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

    protected function parseRequest()
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

    protected function parseResponse($response)
    {
        $produceList = $this->data['paths'][$this->uri][$this->method]['produces'];

        $produce = $response->headers->get('Content-type');

        if (is_null($produce)) {
            $produce = 'text/plain';
        }

        if (!in_array($produce, $produceList)) {
            $this->item['produces'][] = $produce;
        }

        $responses = $this->item['responses'];
        $code = $response->getStatusCode();

        if (!in_array($code, $responses)) {
            $this->saveExample(
                $response->getStatusCode(),
                $response->getContent(),
                $produce
            );
        }
    }

    protected function saveExample($code, $content, $produce)
    {
        $description = $this->getResponseDescription($code);
        $availableContentTypes = [
            'application',
            'text'
        ];
        $explodedContentType = explode('/', $produce);

        if (in_array($explodedContentType[0], $availableContentTypes)) {
            $this->item['responses'][$code] = $this->makeResponseExample($content, $produce, $description);
        } else {
            $this->item['responses'][$code] = '*Unavailable for preview*';
        }
    }

    protected function makeResponseExample($content, $mimeType, $description = ''): array
    {
        $responseExample = ['description' => $description];

        if ($mimeType === 'application/json') {
            $responseExample['schema'] = ['example' => json_decode($content, true)];
        } elseif ($mimeType === 'application/pdf') {
            $responseExample['schema'] = ['example' => base64_encode($content)];
        } else {
            $responseExample['examples']['example'] = $content;
        }

        return $responseExample;
    }

    protected function saveParameters($request, array $annotations)
    {
        $formRequest = new $request;
        $formRequest->setUserResolver($this->request->getUserResolver());
        $formRequest->setRouteResolver($this->request->getRouteResolver());
        $rules = method_exists($formRequest, 'rules') ? $formRequest->rules() : [];
        $attributes = method_exists($formRequest, 'attributes') ? $formRequest->attributes() : [];

        $actionName = $this->getActionName($this->uri);

        if (in_array($this->method, ['get', 'delete'])) {
            $this->saveGetRequestParameters($rules, $attributes, $annotations);
        } else {
            $this->savePostRequestParameters($actionName, $rules, $attributes, $annotations);
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
                    'type' => $this->getParameterType($validation)
                ];
                if (in_array('required', $validation)) {
                    $parameterDefinition['required'] = true;
                }

                $this->item['parameters'][] = $parameterDefinition;
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
            'properties' => []
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

            $rulesArray = array_flip(array_diff_key(array_flip($rulesArray), $uselessRules));

            $this->saveParameterDescription($data, $parameter, $rulesArray, $attributes, $annotations);
        }

        $data['example'] = $this->generateExample($data['properties']);
        $this->data['components']['schemas'][$objectName . 'RequestObject'] = $data;
    }

    protected function getParameterType(array $validation): string
    {
        $validationRules = $this->ruleToTypeMap;
        $validationRules['email'] = 'string';

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

        if (isset($this->data['definitions'][$actionName . 'Object']['properties'])) {
            $objectParametersCount = count($this->data['definitions'][$actionName . 'Object']['properties']);
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

    public function saveConsume()
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
            $this->item['requestBody']['content'][$consume] = [];
        }
    }

    public function saveTags()
    {
        $tagIndex = 1;

        $explodedUri = explode('/', $this->uri);

        $tag = Arr::get($explodedUri, $tagIndex);

        $this->item['tags'] = [$tag];
    }

    public function saveDescription($request, array $annotations)
    {
        $this->item['summary'] = $this->getSummary($request, $annotations);

        $description = Arr::get($annotations, 'description');

        if (!empty($description)) {
            $this->item['description'] = $description;
        }
    }

    public function saveOperationId() {
        $this->item['operationId'] = ucfirst($this->method);
        foreach($this->item['tags'] as $sTag) {
            $this->item['operationId'].=ucfirst($sTag);
        }
        foreach($this->item['parameters'] as $aParam) {
            $this->item['operationId'].=ucfirst($aParam['name']);
        }
    }

    protected function saveSecurity()
    {
        if ($this->requestSupportAuth()) {
            $this->addSecurityToOperation();
        }
    }

    protected function addSecurityToOperation()
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

    protected function parseRequestName($request)
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

    protected function getActionName($uri): string
    {
        $action = preg_replace('[\/]', '', $uri);

        return Str::camel($action);
    }

    protected function saveTempData()
    {
        $exportFile = Arr::get($this->config, 'files.temporary');
        $data = json_encode($this->data);

        file_put_contents($exportFile, $data);
    }

    public function saveProductionData()
    {
        $this->driver->saveData();
    }

    public function getDocFileContent()
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

            $definitions = array_keys($fileContent['definitions']);

            foreach ($definitions as $definition) {
                if (empty($this->documentation['definitions'][$definition])) {
                    $this->documentation['definitions'][$definition] = $fileContent['definitions'][$definition];
                }
            }
        }

        return $this->documentation;
    }

    protected function camelCaseToUnderScore($input): string
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

    protected function replaceObjectValues($parameters): array
    {
        $classNamesValues = [
            File::class => '[uploaded_file]',
        ];

        $parameters = Arr::dot($parameters);
        $returnParameters = [];

        foreach ($parameters as $parameter => $value) {
            if (is_object($value)) {
                $class = get_class($value);

                $value = Arr::get($classNamesValues, $class, $class);
            }

            Arr::set($returnParameters, $parameter, $value);
        }

        return $returnParameters;
    }

    protected function getClassAnnotations($class): array
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
    protected function replaceNullValues($parameters, $types, &$example)
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

    protected function getDefaultValueByType($type)
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
     * @return mixed
     */
    protected function prepareInfo($info)
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
