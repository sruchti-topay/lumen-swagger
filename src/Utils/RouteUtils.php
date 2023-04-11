<?php 

namespace RonasIT\Support\AutoDoc\Utils;

// https://stackoverflow.com/questions/74035886/laravel-get-current-route-path-pattern

class RouteUtils
{
    /**
     * Attempts to match a given request URI with the parameterized URL from route definitions.
     *
     * @param array $urlsWithPlaceholders The route definitions with curly braces for parameters.
     * These typically come from the app()->router->getRoutes() array
     * @param string $inputUrlWithValues The URL to match to the route definitions
     * @return array [url, params] where params is an associative array of the variable names and values
     */
    private static function uriToPath(array $urlsWithPlaceholders, string $inputUrlWithValues): array
    {
        // Remove any trailing slashes
        $inputUrlWithValues = rtrim($inputUrlWithValues, '/');

        // iterate over the parameterized URL definitions (each item is an array from app()->router->getRoutes())
        // (It's actually an associative array - the key name (not needed) is the path prefixed with the HTTP method)
        foreach ($urlsWithPlaceholders as $urlWithVariables) {
            // replace the variables enclosed in curly braces with a regular expression pattern
            $pattern = preg_replace('/\{[^}]+}/', '([^\/]+)', $urlWithVariables);

            // match the input URL with the pattern
            if (preg_match('#^' . $pattern . '$#', $inputUrlWithValues, $matches)) {
                // extract the variable values from the matched input URL
                $variableValues = array_slice($matches, 1);

                // extract the variable names from the URL with variables enclosed in curly braces
                preg_match_all('/\{([^}]+)}/', $urlWithVariables, $variableNames);
                $variableNames = $variableNames[1];

                // combine the variable names and values into an associative array
                $params = array_combine($variableNames, $variableValues);

                // set the result to the matched URL with the variable values
                return [
                    'path'   => $urlWithVariables,
                    'params' => $params
                ];
            }
        }

        return [
            'path'   => '',
            'params' => []
        ];
    }

    /**
     * Helper to get the current path pattern from the current request, 
     * with the original parameterized segments,
     * as efficiently as possible using filters and regex
     *
     * @return string
     */
    public static function getPathDefinition($request = null): string
    {
        // Grab the request and the routes from Lumen
        if(is_null($request)) {
            $request = app('request');
        }
        $appRoutes   = app()->router->getRoutes();
        if (!$request && $appRoutes) return '';

        $reqMethod   = $request->method();
        $reqUri      = $request->getPathInfo(); // without the query string
        $reqSegments = count(explode('/', $reqUri)); // number of segments in the path

        // First shortlist the possible routes using simpler filters,
        // before applying the expensive regex. We'll avoid doing regex on every route.
        $shortList = [];
        foreach ($appRoutes as &$routeInfo) {
            // Skip routes with a different HTTP method
            if ($routeInfo['method'] !== $reqMethod) continue;

            // Skip routes with a different number of segments
            // $routeInfo['uri'] is the path as defined in the route file with curly braces for parameters
            if (count(explode('/', $routeInfo['uri'])) !== $reqSegments) continue;

            // Skip if first piece of the path before parameters doesn't match the current full request
            // Get everything before the first parameterized segment
            $pathPattern = ($lTrim = strstr($routeInfo['uri'], "/{", true)) !== false ? $lTrim : $routeInfo['uri'];
            if (!str_contains($reqUri, $pathPattern)) continue;

            // From here we've quickly narrowed down possible routes to a short list, probably within same controller
            // Usually this array will contain just 1 route. Much faster to apply the regex from here
            $shortList[] = $routeInfo['uri'];
        }
        // Always unset references when done with them
        unset($routeInfo);

        // Now try to match the exact request to the route including parameters
        $resultPath = self::uriToPath($shortList, $reqUri);
        return ($resultPath['path'] ?? '[unknown]');
    }

}