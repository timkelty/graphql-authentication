<?php

namespace jamesedmonston\graphqlauthentication\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\models\GqlToken;
use craft\services\Gql;
use DateTime;
use DateTimeImmutable;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\Type;
use jamesedmonston\graphqlauthentication\elements\RefreshToken;
use jamesedmonston\graphqlauthentication\gql\JWT;
use jamesedmonston\graphqlauthentication\GraphqlAuthentication;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use yii\base\Event;
use yii\base\InvalidArgumentException;
use yii\web\BadRequestHttpException;

class TokenService extends Component
{
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
            Gql::EVENT_REGISTER_GQL_MUTATIONS,
            [$this, 'registerGqlMutations']
        );
    }

    public function registerGqlMutations(Event $event)
    {
        $settings = GraphqlAuthentication::$plugin->getSettings();

        if ($settings->tokenType === 'jwt') {
            $event->mutations['refreshToken'] = [
                'description' => "Refreshes a user's JWT. It first checks for the occurence of the automatically-set `gql_refreshToken` cookie, and falls back to the argument.",
                'type' => Type::nonNull(JWT::getType()),
                'args' => [
                    'refreshToken' => Type::string(),
                ],
                'resolve' => function ($source, array $arguments) use ($settings) {
                    $refreshToken = $_COOKIE['gql_refreshToken'] ?? $arguments['refreshToken'] ?? null;

                    if (!$refreshToken) {
                        throw new Error('Invalid Refresh Token');
                    }

                    $tokenEntry = RefreshToken::find()->where(['token' => $refreshToken])->one();

                    if (!$tokenEntry) {
                        throw new Error('Invalid Refresh Token');
                    }

                    $user = Craft::$app->getUsers()->getUserById($tokenEntry->userId);

                    if (!$user) {
                        throw new Error($settings->userNotFound);
                    }

                    $schemaId = $tokenEntry->schemaId;

                    if (!$user) {
                        throw new Error($settings->invalidSchema);
                    }

                    $token = $this->create($user, $schemaId);
                    return $token;
                },
            ];
        }
    }

    public function getHeaderToken(): GqlToken
    {
        $request = Craft::$app->getRequest();
        $requestHeaders = $request->getHeaders();
        $settings = GraphqlAuthentication::$plugin->getSettings();

        switch ($settings->tokenType) {
            case 'response':
                foreach ($requestHeaders->get('authorization', [], false) as $authHeader) {
                    $authValues = array_map('trim', explode(',', $authHeader));

                    foreach ($authValues as $authValue) {
                        if (preg_match('/^Bearer\s+(.+)$/i', $authValue, $matches)) {
                            try {
                                $token = Craft::$app->getGql()->getTokenByAccessToken($matches[1]);
                            } catch (InvalidArgumentException $e) {
                                throw new InvalidArgumentException($e);
                            }

                            if (!$token) {
                                throw new BadRequestHttpException($settings->invalidHeader);
                            }

                            break 2;
                        }
                    }
                }

                if (!isset($token)) {
                    throw new BadRequestHttpException($settings->invalidHeader);
                }

                $this->_validateExpiry($token);
                return $token;

            case 'cookie':
                try {
                    $token = Craft::$app->getGql()->getTokenByAccessToken($_COOKIE['gql_accessToken']);
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException($e);
                }

                if (!isset($token)) {
                    throw new BadRequestHttpException($settings->invalidHeader);
                }

                $this->_validateExpiry($token);
                return $token;

            case 'jwt':
                foreach ($requestHeaders->get('authorization', [], false) as $authHeader) {
                    $authValues = array_map('trim', explode(',', $authHeader));

                    foreach ($authValues as $authValue) {
                        if (preg_match('/^JWT\s+(.+)$/i', $authValue, $matches)) {
                            try {
                                $jwtConfig = Configuration::forSymmetricSigner(
                                    new Sha256(),
                                    InMemory::plainText($settings->jwtSecretKey),
                                );

                                $jwt = $jwtConfig->parser()->parse($matches[1]);

                                $validator = new SignedWith(new Sha256(), InMemory::plainText($settings->jwtSecretKey));
                                $jwtConfig->setValidationConstraints($validator);
                                $constraints = $jwtConfig->validationConstraints();

                                try {
                                    $jwtConfig->validator()->assert($jwt, ...$constraints);
                                } catch (RequiredConstraintsViolated $e) {
                                    throw new Error(json_encode($e->violations()));
                                }

                                $accessToken = $jwt->claims()->get('accessToken');
                                $token = Craft::$app->getGql()->getTokenByAccessToken($accessToken);
                            } catch (InvalidArgumentException $e) {
                                throw new InvalidArgumentException($e);
                            }

                            if (!$token) {
                                throw new BadRequestHttpException($settings->invalidHeader);
                            }

                            break 2;
                        }
                    }
                }

                if (!isset($token)) {
                    throw new BadRequestHttpException($settings->invalidHeader);
                }

                $this->_validateExpiry($token);
                return $token;
        }
    }

    public function getUserFromToken(): User
    {
        return Craft::$app->getUsers()->getUserById($this->_extractUserId());
    }

    public function create(User $user, Int $schemaId)
    {
        $settings = GraphqlAuthentication::$plugin->getSettings();
        $accessToken = Craft::$app->getSecurity()->generateRandomString(32);
        $time = microtime(true);

        $fields = [
            'name' => "user-{$user->id}-{$time}",
            'accessToken' => $accessToken,
            'enabled' => true,
            'schemaId' => $schemaId,
        ];

        switch ($settings->tokenType) {
            case 'response':
            case 'cookie':
                if ($settings->expiration) {
                    $fields['expiryDate'] = (new DateTime())->modify("+ {$settings->expiration}");
                }
                break;

            case 'jwt':
                $fields['expiryDate'] = (new DateTime())->modify("+ {$settings->jwtExpiration}");
                break;

            default:
                break;
        }

        $token = new GqlToken($fields);

        if (!Craft::$app->getGql()->saveToken($token)) {
            throw new Error(json_encode($token->getErrors()));
        }

        if ($settings->tokenType !== 'jwt') {
            if ($settings->tokenType === 'cookie') {
                $this->_setCookie('gql_accessToken', $accessToken, $settings->expiration);
            }

            return $accessToken;
        }

        if (!$settings->jwtSecretKey) {
            throw new Error('Invalid JWT Secret Key');
        }

        $jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($settings->jwtSecretKey),
        );

        $now = new DateTimeImmutable();

        $jwt = $jwtConfig->builder()
            ->issuedBy(UrlHelper::cpUrl())
            ->issuedAt($now)
            ->expiresAt($now->modify($settings->jwtExpiration))
            ->withClaim('userId', $user->id)
            ->withClaim('fullName', $user->fullName)
            ->withClaim('accessToken', $accessToken)
            ->withClaim('schema', $token->getSchema()->name)
            ->getToken($jwtConfig->signer(), $jwtConfig->signingKey());

        $jwtExpiration = date_create(date('Y-m-d H:i:s'))->modify("+ {$settings->jwtExpiration}");
        $refreshToken = Craft::$app->getSecurity()->generateRandomString(32);
        $refreshTokenExpiration = date_create(date('Y-m-d H:i:s'))->modify("+ {$settings->jwtRefreshExpiration}");

        $tokenEntry = new RefreshToken([
            'token' => $refreshToken,
            'userId' => $user->id,
            'schemaId' => $schemaId,
            'expiryDate' => $refreshTokenExpiration->format('Y-m-d H:i:s'),
        ]);

        if (!Craft::$app->getElements()->saveElement($tokenEntry)) {
            throw new Error(json_encode($tokenEntry->getErrors()));
        }

        $this->_setCookie('gql_refreshToken', $refreshToken, $settings->jwtRefreshExpiration);

        return [
            'jwt' => $jwt->toString(),
            'jwtExpiresAt' => $jwtExpiration->getTimestamp(),
            'refreshToken' => $refreshToken,
            'refreshTokenExpiresAt' => $refreshTokenExpiration->getTimestamp(),
        ];
    }

    // Protected Methods
    // =========================================================================

    protected function _setCookie(string $name, string $token, $expiration = null): bool
    {
        $settings = GraphqlAuthentication::$plugin->getSettings();
        $expiry = 0;

        if ($expiration) {
            $expiry = strtotime((new DateTime())->modify("+ {$expiration}")->format('Y-m-d H:i:s'));
        }

        if (PHP_VERSION_ID < 70300) {
            return setcookie($name, $token, $expiry, "/; samesite={$settings->sameSitePolicy}", '', true, true);
        }

        return setcookie($name, $token, [
            'expires' => $expiry,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => $settings->sameSitePolicy,
        ]);
    }

    protected function _extractUserId(): string
    {
        $token = $this->getHeaderToken();
        return explode('-', $token->name)[1];
    }

    protected function _validateExpiry(GqlToken $token)
    {
        if (!$token->expiryDate) {
            return;
        }

        if (strtotime(date('Y-m-d H:i:s')) < strtotime($token->expiryDate->format('Y-m-d H:i:s'))) {
            return;
        }

        throw new BadRequestHttpException(GraphqlAuthentication::$plugin->getSettings()->invalidHeader);
    }
}