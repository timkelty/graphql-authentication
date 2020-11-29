<?php

namespace jamesedmonston\graphqlauthentication\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\gql\arguments\elements\User as UserArguments;
use craft\gql\types\generators\UserType;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use craft\records\User as UserRecord;
use craft\services\Gql;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\Type;
use jamesedmonston\graphqlauthentication\gql\Auth;
use jamesedmonston\graphqlauthentication\GraphqlAuthentication;
use yii\base\Event;

class UserService extends Component
{
    public static $INVALID_LOGIN = "We couldn't log you in with the provided details";
    public static $INVALID_PASSWORD_UPDATE = "We couldn't update the password with the provided details";
    public static $INVALID_USER_UPDATE = "We couldn't update the user with the provided details";
    public static $INVALID_REQUEST = 'Cannot validate request';
    public static $INVALID_PASSWORD_MATCH = 'New passwords do not match';
    public static $INVALID_SCHEMA = 'No schema has been set for this user group';
    public static $TOKEN_NOT_FOUND = "We couldn't find any matching tokens";
    public static $USER_NOT_FOUND = "We couldn't find any matching users";

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            [$this, 'registerGqlQueries']
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_MUTATIONS,
            [$this, 'registerGqlMutations']
        );
    }

    public function registerGqlQueries(Event $event)
    {
        $event->queries['getUser'] = [
            'description' => 'Gets authenticated user.',
            'type' => UserType::generateType(User::class),
            'args' => [],
            'resolve' => function () {
                $user = GraphqlAuthentication::$plugin->getInstance()->token->getUserFromToken();

                if (!$user) {
                    throw new Error(self::$USER_NOT_FOUND);
                }

                return $user;
            },
        ];
    }

    public function registerGqlMutations(Event $event)
    {
        $elements = Craft::$app->getElements();
        $users = Craft::$app->getUsers();
        $permissions = Craft::$app->getUserPermissions();
        $gql = Craft::$app->getGql();
        $settings = GraphqlAuthentication::$plugin->getSettings();
        $tokenService = GraphqlAuthentication::$plugin->getInstance()->token;

        $event->mutations['authenticate'] = [
            'description' => 'Logs a user in. Returns user and token.',
            'type' => Type::nonNull(Auth::getType()),
            'args' => [
                'email' => Type::nonNull(Type::string()),
                'password' => Type::nonNull(Type::string()),
            ],
            'resolve' => function ($source, array $arguments) use ($gql, $tokenService, $settings) {
                $user = $this->_authenticate($arguments);
                $schemaId = $settings->schemaId ?? null;

                if ($settings->permissionType === 'multiple') {
                    $userGroup = $user->getGroups()[0] ?? null;

                    if ($userGroup) {
                        $schemaId = $settings->granularSchemas["group-{$userGroup->id}"]['schemaId'] ?? null;
                    }
                }

                if (!$schemaId) {
                    throw new Error(self::$INVALID_SCHEMA);
                }

                $token = $tokenService->create($user, $schemaId);

                return [
                    'accessToken' => $token,
                    'user' => $user,
                    'schema' => $gql->getSchemaById($schemaId)->name,
                ];
            },
        ];

        if ($settings->permissionType === 'single' && $settings->allowRegistration) {
            $event->mutations['register'] = [
                'description' => 'Registers a user. Returns user and token.',
                'type' => Type::nonNull(Auth::getType()),
                'args' => array_merge(
                    [
                        'email' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string()),
                        'firstName' => Type::nonNull(Type::string()),
                        'lastName' => Type::nonNull(Type::string()),
                    ],
                    UserArguments::getContentArguments()
                ),
                'resolve' => function ($source, array $arguments) use ($gql, $tokenService, $settings) {
                    $schemaId = $settings->schemaId;

                    if (!$schemaId) {
                        throw new Error(self::$INVALID_SCHEMA);
                    }

                    $user = $this->create($arguments, $settings->userGroup);
                    $token = $tokenService->create($user, $schemaId);

                    return [
                        'accessToken' => $token,
                        'user' => $user,
                        'schema' => $gql->getSchemaById($schemaId)->name,
                    ];
                },
            ];
        }

        if ($settings->permissionType === 'multiple') {
            $userGroups = Craft::$app->getUserGroups()->getAllGroups();

            foreach ($userGroups as $userGroup) {
                if (!($settings->granularSchemas["group-{$userGroup->id}"]['allowRegistration'] ?? false)) {
                    continue;
                }

                $handle = ucfirst($userGroup->handle);

                $event->mutations["register{$handle}"] = [
                    'description' => "Registers a {$userGroup->name} user. Returns user and token.",
                    'type' => Type::nonNull(Auth::getType()),
                    'args' => array_merge(
                        [
                            'email' => Type::nonNull(Type::string()),
                            'password' => Type::nonNull(Type::string()),
                            'firstName' => Type::nonNull(Type::string()),
                            'lastName' => Type::nonNull(Type::string()),
                        ],
                        UserArguments::getContentArguments()
                    ),
                    'resolve' => function ($source, array $arguments) use ($gql, $tokenService, $settings, $userGroup) {
                        $schemaId = $settings->granularSchemas["group-{$userGroup->id}"]['schemaId'] ?? null;

                        if (!$schemaId) {
                            throw new Error(self::$INVALID_SCHEMA);
                        }

                        $user = $this->create($arguments, $userGroup->id);
                        $token = $tokenService->create($user, $schemaId);

                        return [
                            'accessToken' => $token,
                            'user' => $user,
                            'schema' => $gql->getSchemaById($schemaId)->name,
                        ];
                    },
                ];
            }
        }

        $event->mutations['forgottenPassword'] = [
            'description' => "Sends a password reset email to the user's email address. Returns success message.",
            'type' => Type::nonNull(Type::string()),
            'args' => [
                'email' => Type::nonNull(Type::string()),
            ],
            'resolve' => function ($source, array $arguments) use ($users) {
                $email = $arguments['email'];
                $user = $users->getUserByUsernameOrEmail($email);
                $message = 'You will receive an email if it matches an account in our system';

                if (!$user) {
                    return $message;
                }

                $users->sendPasswordResetEmail($user);
                return $message;
            },
        ];

        $event->mutations['setPassword'] = [
            'description' => 'Sets password for unauthenticated user. Requires `code` and `id` from Craft reset password email. Returns success message.',
            'type' => Type::nonNull(Type::string()),
            'args' => [
                'password' => Type::nonNull(Type::string()),
                'code' => Type::nonNull(Type::string()),
                'id' => Type::nonNull(Type::string()),
            ],
            'resolve' => function ($source, array $arguments) use ($elements, $users) {
                $password = $arguments['password'];
                $code = $arguments['code'];
                $id = $arguments['id'];

                $user = $users->getUserByUid($id);

                if (!$user || !$users->isVerificationCodeValidForUser($user, $code)) {
                    throw new Error(self::$INVALID_REQUEST);
                }

                $user->newPassword = $password;

                if (!$elements->saveElement($user)) {
                    throw new Error(json_encode($user->getErrors()));
                }

                return 'Successfully saved password';
            },
        ];

        $event->mutations['updatePassword'] = [
            'description' => 'Updates password for authenticated user. Requires access token and current password. Returns success message.',
            'type' => Type::nonNull(Type::string()),
            'args' => [
                'currentPassword' => Type::nonNull(Type::string()),
                'newPassword' => Type::nonNull(Type::string()),
                'confirmPassword' => Type::nonNull(Type::string()),
            ],
            'resolve' => function ($source, array $arguments) use ($elements, $users, $permissions, $tokenService) {
                $user = $tokenService->getUserFromToken();

                if (!$user) {
                    throw new Error(self::$INVALID_PASSWORD_UPDATE);
                }

                $newPassword = $arguments['newPassword'];
                $confirmPassword = $arguments['confirmPassword'];

                if ($newPassword !== $confirmPassword) {
                    throw new Error(self::$INVALID_PASSWORD_MATCH);
                }

                $currentPassword = $arguments['currentPassword'];
                $userPermissions = $permissions->getPermissionsByUserId($user->id);

                if (!in_array('accessCp', $userPermissions)) {
                    $permissions->saveUserPermissions($user->id, array_merge($userPermissions, ['accessCp']));
                }

                $user = $users->getUserByUsernameOrEmail($user->email);

                if (!$user->authenticate($currentPassword)) {
                    $permissions->saveUserPermissions($user->id, $userPermissions);
                    throw new Error(self::$INVALID_PASSWORD_UPDATE);
                }

                $permissions->saveUserPermissions($user->id, $userPermissions);

                $user->newPassword = $newPassword;

                if (!$elements->saveElement($user)) {
                    throw new Error(json_encode($user->getErrors()));
                }

                return 'Successfully updated password';
            },
        ];

        $event->mutations['updateUser'] = [
            'description' => 'Updates authenticated user. Returns user.',
            'type' => UserType::generateType(User::class),
            'args' => array_merge(
                [
                    'email' => Type::string(),
                    'firstName' => Type::string(),
                    'lastName' => Type::string(),
                ],
                UserArguments::getContentArguments()
            ),
            'resolve' => function ($source, array $arguments) use ($elements, $tokenService) {
                $user = $tokenService->getUserFromToken();

                if (!$user) {
                    throw new Error(self::$INVALID_USER_UPDATE);
                }

                if (isset($arguments['email'])) {
                    $user->username = $arguments['email'];
                    $user->email = $arguments['email'];
                }

                if (isset($arguments['firstName'])) {
                    $user->firstName = $arguments['firstName'];
                }

                if (isset($arguments['lastName'])) {
                    $user->lastName = $arguments['lastName'];
                }

                $customFields = UserArguments::getContentArguments();

                foreach ($customFields as &$key) {
                    if (is_array($key) && isset($key['name'])) {
                        $key = $key['name'];
                    }

                    if (!isset($arguments[$key]) || !count($arguments[$key])) {
                        continue;
                    }

                    $user->setFieldValue($key, $arguments[$key][0]);
                }

                if (!$elements->saveElement($user)) {
                    throw new Error(json_encode($user->getErrors()));
                }

                return $user;
            },
        ];

        $event->mutations['deleteCurrentToken'] = [
            'description' => 'Deletes authenticated user access token. Useful for logging out of current device. Returns boolean.',
            'type' => Type::nonNull(Type::boolean()),
            'args' => [],
            'resolve' => function () use ($gql, $tokenService) {
                $token = $tokenService->getHeaderToken();

                if (!$token) {
                    throw new Error(self::$TOKEN_NOT_FOUND);
                }

                $gql->deleteTokenById($token->id);

                return true;
            },
        ];

        $event->mutations['deleteAllTokens'] = [
            'description' => 'Deletes all access tokens belonging to the authenticated user. Useful for logging out of all devices. Returns boolean.',
            'type' => Type::nonNull(Type::boolean()),
            'args' => [],
            'resolve' => function () use ($gql, $tokenService) {
                $user = $tokenService->getUserFromToken();

                if (!$user) {
                    throw new Error(self::$TOKEN_NOT_FOUND);
                }

                $savedTokens = $gql->getTokens();

                if (!$savedTokens || !count($savedTokens)) {
                    throw new Error(self::$TOKEN_NOT_FOUND);
                }

                foreach ($savedTokens as $savedToken) {
                    if (StringHelper::contains($savedToken->name, "user-{$user->id}")) {
                        $gql->deleteTokenById($savedToken->id);
                    }
                }

                return true;
            },
        ];
    }

    public function create(array $arguments, Int $userGroup): User
    {
        $email = $arguments['email'];
        $password = $arguments['password'];
        $firstName = $arguments['firstName'];
        $lastName = $arguments['lastName'];

        $user = new User();
        $user->username = $email;
        $user->email = $email;
        $user->firstName = $firstName;
        $user->lastName = $lastName;

        if ($password) {
            $user->newPassword = $password;
        }

        $customFields = UserArguments::getContentArguments();

        foreach ($customFields as $key) {
            if (is_array($key) && isset($key['name'])) {
                $key = $key['name'];
            }

            if (!isset($arguments[$key]) || !count($arguments[$key])) {
                continue;
            }

            $user->setFieldValue($key, $arguments[$key][0]);
        }

        $requiresVerification = Craft::$app->getProjectConfig()->get('users.requireEmailVerification');

        if ($requiresVerification) {
            $user->pending = true;
        }

        $elements = Craft::$app->getElements();

        if (!$elements->saveElement($user)) {
            throw new Error(json_encode($user->getErrors()));
        }

        $users = Craft::$app->getUsers();

        if ($userGroup) {
            $users->assignUserToGroups($user->id, [$userGroup]);
        }

        if ($requiresVerification) {
            $users->sendActivationEmail($user);
        }

        $this->_updateLastLogin($user);
        return $user;
    }

    // Protected Methods
    // =========================================================================

    protected function _authenticate(array $arguments): User
    {
        $email = $arguments['email'];
        $password = $arguments['password'];

        $users = Craft::$app->getUsers();
        $user = $users->getUserByUsernameOrEmail($email);

        if (!$user) {
            throw new Error(self::$INVALID_LOGIN);
        }

        $permissions = Craft::$app->getUserPermissions();
        $userPermissions = $permissions->getPermissionsByUserId($user->id);

        if (!in_array('accessCp', $userPermissions)) {
            $permissions->saveUserPermissions($user->id, array_merge($userPermissions, ['accessCp']));
        }

        if (!$user->authenticate($password)) {
            $permissions->saveUserPermissions($user->id, $userPermissions);
            throw new Error(self::$INVALID_LOGIN);
        }

        $permissions->saveUserPermissions($user->id, $userPermissions);

        $this->_updateLastLogin($user);
        return $user;
    }

    protected function _updateLastLogin(User $user)
    {
        $now = DateTimeHelper::currentUTCDateTime();
        $userRecord = UserRecord::findOne($user->id);
        $userRecord->lastLoginDate = $now;
        $userRecord->save();
    }
}
