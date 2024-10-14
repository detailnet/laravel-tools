<?php

declare(strict_types=1);

namespace DetailTest\Laravel\Api;

use Detail\Laravel\Api\UserModel;
use Generator;
use PHPUnit\Framework\TestCase;
use function explode;

class UserModelAllowsResourceTest extends TestCase
{
    public static function provideModelAndRoutes(): Generator
    {
        $getModel = static function(array $resources, bool $active = true): UserModel {
            $model = new UserModel(); // Can't pass params because no mass assignment are declared
            $model->active = $active;
            $model->resources = $resources;

            return $model;
        };

        yield 'All permitted on "full" wildcard' => [
            $getModel(['*:*']),
            [
                'GET:dummy' => true,
                'GET:dummy/dummy' => true,
                'GET:dummy ' => false, // Invalid path input test
                'GET: dummy' => false, // Invalid path input test
                'GET:dum?y' => false, // Invalid path input test
                'GET:dum*y' => false, // Invalid path input test
            ]
        ];

        yield 'All methods permitted for fixed path on method wildcard' => [
            $getModel(['*:dummy']),
            [
                'GET:dummy' => true,
                'POST:dummy' => true,
                'PUT:dummy' => true,
                'OPTIONS:dummy' => true,
                'HEAD:dummy' => false, // Invalid method
                'GET:dummy-2' => false,
                'GET:*' => false, // Invalid path input test
                '*:dummy' => false, // Invalid method input test
                '*:dummy-2' => false, // Invalid method input test
            ]
        ];

        yield 'Only given methods permitted for fixed path' => [
            $getModel(['GET:dummy']),
            [
                'GET:dummy' => true,
                'POST:dummy' => false,
                'GETS:dummy' => false,
                ' GET:dummy' => false,
                'GET :dummy' => false,
                'GET:dummy-2' => false,
                'GET:dumm' => false,
                'GET:*' => false, // Invalid path input test
                '*:dummy' => false, // Invalid method input test
            ]
        ];

        yield 'All paths are permitted for a specific method' => [
            $getModel(['GET:*']),
            [
                'GET:dummy' => true,
                'GET:dummy/dummy' => true,
                'GET:dummy/dummy/dummy' => true,
                'POST:dummy' => false,
                'GET:*' => false, // Invalid path input test
            ]
        ];

        yield 'Some paths are permitted for ending wildcard path' => [
            $getModel(['GET:dummy/*']),
            [
                'GET:dummy' => false,
                'GET:dummy/dummy' => true,
                'POST:dummy/dummy' => false,
                'GET:dummy/dummy/dummy' => false, // IMPORTANT: Only when path is exactly '*' the sub-routes are allowed
                'GET:dummy-2/dummy' => false,
                'GET:dummy/*' => false, // Invalid path input test
            ]
        ];

        yield 'Some paths are permitted for initial wildcard path' => [
            $getModel(['GET:*/dummy']),
            [
                'GET:dummy' => false,
                'GET:dummy/dummy' => true,
                'POST:dummy/dummy' => false,
                'GET:dummy/dummy/dummy' => false,
                'GET:dummy/dummy-2' => false,
                'GET:*/dummy' => false, // Invalid path input test
            ]
        ];

        yield 'Some paths are permitted for middle wildcard path' => [
            $getModel(['GET:dummy/*/dummy']),
            [
                'GET:dummy' => false,
                'GET:dummy/dummy' => false,
                'GET:dummy/dummy/dummy' => true,
                'POST:dummy/dummy/dummy' => false,
                'GET:dummy-2/dummy/dummy' => false,
                'GET:dummy/dummy/dummy-2' => false,
                'GET:dummy//dummy' => true, // Invalid path input test, passes because '*' does match empty chars too
                'GET:dummy/./dummy' => false, // Invalid path input test, does not passes because period is disabled
                'GET:dummy/../dummy' => false, // Invalid path input test, does not passes because period is disabled
                'GET:dummy/*/dummy' => false, // Invalid path input test
            ]
        ];

        yield 'Some paths are permitted for words containing * wildcard path' => [
            $getModel(['GET:du*y']),
            [
                'GET:dummy' => true,
                'GET:dummy/dummy' => false, // This would pass using fnmatch without FNM_PATHNAME
                'GET:dumy' => true,
                'GET:dummmy' => true,
                'GET:duy' => true, // IMPORTANT: '*' does match empty chars too
                'GET:du*y' => false, // Invalid path input test
                'GET:du/y' => false, // IMPORTANT: '*' does not match '/' using fnmatch without FNM_PATHNAME
            ]
        ];

        yield 'Some paths are permitted for words containing ? wildcard path' => [
            $getModel(['GET:du??y']),
            [
                'GET:dummy' => true,
                'GET:dummy/dummy' => false,
                'GET:duxxy' => true,
                'GET:dumy' => false,
                'GET:duy' => false, // Important: '?' matches at leas 1 char
                'GET:du//y' => false, // Important: '?' does not match '/' using fnmatch without FNM_PATHNAME
                'GET:du??y' => false, // Invalid path input test
                'GET:du  y' => false, // Invalid path input test
            ]
        ];

        yield 'Full test with many allowed resources of any kind' => [
            $getModel(['GET:dummy', 'OPTIONS:*', 'POST:test/dummy/*', 'PATCH:test/*/dummy']),
            [
                'GET:dummy' => true,
                'POST:dummy' => false,
                'OPTIONS:dummy' => true,
                'GETS:dummy' => false,
                ' GET:dummy' => false,
                'GET :dummy' => false,
                'GET:dummy-2' => false,
                'OPTIONS:dummy-2' => true,
                'POST:test/dummy' => false,
                'OPTIONS:test/dummy' => true,
                'PATCH:test/dummy' => false,
                'POST:test/dummy/dummy' => true,
                'OPTIONS:test/dummy/dummy' => true,
                'PATCH:test/dummy/dummy' => true,
            ]
        ];

        yield 'All denied if not active' => [
            $getModel(['*:*'], false),
            [
                'GET:dummy' => false,
            ]
        ];
    }

    /**
     * @param UserModel $apiUser
     * @param array<string, bool> $resourceTestExpectedResult
     * @dataProvider provideModelAndRoutes
     */
    public function testAllowResource(UserModel $apiUser, array $resourceTestExpectedResult): void
    {
        foreach ($resourceTestExpectedResult as $resource => $expectedResult) {
            [$method, $path] = explode(':', $resource, 2);

            self::assertEquals(
                $expectedResult,
                $apiUser->allowsResource($method, $path),
                $resource . ' should' .($expectedResult ? '' : 'n\'t') . ' be allowed'
            );
        }
    }
}
