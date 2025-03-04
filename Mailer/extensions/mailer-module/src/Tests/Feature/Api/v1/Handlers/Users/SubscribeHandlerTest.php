<?php
declare(strict_types=1);

namespace Tests\Feature\Api\v1\Handlers\Users;

use Nette\Http\IResponse;
use Nette\Utils\Json;
use Remp\MailerModule\Api\v1\Handlers\Users\SubscribeHandler;
use Tests\Feature\Api\BaseApiHandlerTestCase;
use Tomaj\NetteApi\Response\JsonApiResponse;

class SubscribeHandlerTest extends BaseApiHandlerTestCase
{
    /** @var SubscribeHandler */
    private $handler;

    public function setUp(): void
    {
        parent::setUp();
        $this->handler = $this->getHandler(SubscribeHandler::class);
    }

    public function testSuccessfulSubscribeWithID()
    {
        $mailType = $this->createMailTypeWithCategory(
            "category1",
            "code1",
            "name1"
        );

        $params = [
            'raw' => Json::encode([
                'user_id' => 123,
                'email' => 'example@example.com',
                'list_id' => $mailType->id,
            ])
        ];

        /** @var JsonApiResponse $response */
        $response = $this->handler->handle($params);
        $this->assertInstanceOf(JsonApiResponse::class, $response);
        $this->assertEquals(IResponse::S200_OK, $response->getCode());

        $isSubscribed = (bool) $this->userSubscriptionsRepository->getTable()->where([
            'user_id' => 123,
            'user_email' => 'example@example.com',
            'mail_type_id' => $mailType->id,
            'subscribed' => 1,
        ])->count('*');
        $this->assertTrue($isSubscribed);
    }

    public function testSuccessfulSubscribeWithCode()
    {
        $mailType = $this->createMailTypeWithCategory(
            "category1",
            "code1",
            "name1"
        );

        $params = [
            'raw' => Json::encode([
                'user_id' => 123,
                'email' => 'example@example.com',
                'list_code' => $mailType->code,
            ])
        ];

        /** @var JsonApiResponse $response */
        $response = $this->handler->handle($params);
        $this->assertInstanceOf(JsonApiResponse::class, $response);
        $this->assertEquals(IResponse::S200_OK, $response->getCode());

        $isSubscribed = (bool) $this->userSubscriptionsRepository->getTable()->where([
            'user_id' => 123,
            'user_email' => 'example@example.com',
            'mail_type_id' => $mailType->id,
            'subscribed' => 1,
        ])->count('*');
        $this->assertTrue($isSubscribed);
    }

    public function testSuccessfulSubscribeHavingMultipleVariants()
    {
        $mailType = $this->createMailTypeWithCategory(isMultiVariant: true);
        $variant1 = $this->createMailTypeVariant($mailType, 'Foo', 'foo');
        $variant2 = $this->createMailTypeVariant($mailType, 'Foo', 'foo');

        /** @var JsonApiResponse $response */
        $response = $this->handler->handle([
            'raw' => Json::encode([
                    'user_id' => 123,
                    'email' => 'example@example.com',
                    'list_code' => $mailType->code,
                ])
        ]);
        $this->assertInstanceOf(JsonApiResponse::class, $response);
        $this->assertEquals(IResponse::S200_OK, $response->getCode());
        $payload = $response->getPayload();
        $this->assertEquals('ok', $payload['status']);
        $this->assertEqualsCanonicalizing([
            (object) [
                'id' => $variant1->id,
                'title' => $variant1->title,
                'code' => $variant1->code,
                'sorting' => $variant1->sorting,
            ], (object) [
                'id' => $variant2->id,
                'title' => $variant2->title,
                'code' => $variant2->code,
                'sorting' => $variant2->sorting,
            ]
        ], $payload['subscribed_variants']);
    }

    public function testSuccessfulSubscribeHavingMultipleVariantsWithDefaultVariant()
    {
        $mailType = $this->createMailTypeWithCategory(isMultiVariant: true);
        $variant1 = $this->createMailTypeVariant($mailType, 'Foo', 'foo', isDefaultVariant: true);
        $variant2 = $this->createMailTypeVariant($mailType, 'Foo', 'foo');

        /** @var JsonApiResponse $response */
        $response = $this->handler->handle([
            'raw' => Json::encode([
                'user_id' => 123,
                'email' => 'example@example.com',
                'list_code' => $mailType->code,
            ])
        ]);
        $this->assertInstanceOf(JsonApiResponse::class, $response);
        $this->assertEquals(IResponse::S200_OK, $response->getCode());
        $payload = $response->getPayload();
        $this->assertEquals('ok', $payload['status']);
        $this->assertEqualsCanonicalizing([
            (object) [
                'id' => $variant1->id,
                'title' => $variant1->title,
                'code' => $variant1->code,
                'sorting' => $variant1->sorting,
            ]
        ], $payload['subscribed_variants']);
    }

    public function testSuccessfulSubscribeWithVariantId()
    {
        $mailType = $this->createMailTypeWithCategory(isMultiVariant: true);
        $variant = $this->createMailTypeVariant($mailType, 'Foo', 'foo');
        $variantNotSubscribed = $this->createMailTypeVariant($mailType, 'Foo2', 'foo2');

        $params = [
            'raw' => Json::encode([
                'user_id' => 123,
                'email' => 'example@example.com',
                'list_code' => $mailType->code,
                'variant_id' => $variant->id,
            ])
        ];

        /** @var JsonApiResponse $response */
        $response = $this->handler->handle($params);
        $this->assertInstanceOf(JsonApiResponse::class, $response);
        $this->assertEquals(IResponse::S200_OK, $response->getCode());

        $isSubscribed = (bool) $this->userSubscriptionVariantsRepository->getTable()->where([
            'mail_user_subscription.user_id' => 123,
            'mail_user_subscription.user_email' => 'example@example.com',
            'mail_user_subscription.subscribed' => 1,
            'mail_type_variant_id' => $variant->id,
        ])->count('*');
        $this->assertTrue($isSubscribed);

        $payload = $response->getPayload();
        $this->assertEquals('ok', $payload['status']);
        $this->assertEqualsCanonicalizing([
            (object) [
                'id' => $variant->id,
                'title' => $variant->title,
                'code' => $variant->code,
                'sorting' => $variant->sorting,
            ]
        ], $payload['subscribed_variants']);
    }

    public function testSuccessfulSubscribeWithVariantCode()
    {
        $mailType = $this->createMailTypeWithCategory(
            "category1",
            "code1",
            "name1"
        );
        $variant = $this->createMailTypeVariant($mailType, 'Foo', 'foo');

        $params = [
            'raw' => Json::encode([
                'user_id' => 123,
                'email' => 'example@example.com',
                'list_code' => $mailType->code,
                'variant_code' => $variant->code,
            ])
        ];

        /** @var JsonApiResponse $response */
        $response = $this->handler->handle($params);
        $this->assertInstanceOf(JsonApiResponse::class, $response);
        $this->assertEquals(IResponse::S200_OK, $response->getCode());

        $isSubscribed = (bool) $this->userSubscriptionVariantsRepository->getTable()->where([
            'mail_user_subscription.user_id' => 123,
            'mail_user_subscription.user_email' => 'example@example.com',
            'mail_user_subscription.subscribed' => 1,
            'mail_type_variant_id' => $variant->id,
        ])->count('*');
        $this->assertTrue($isSubscribed);
    }

    public function testSuccessfulSubscribeWithRtmParams()
    {
        $mailType = $this->createMailTypeWithCategory(
            "category1",
            "code1",
            "name1"
        );
        $variant = $this->createMailTypeVariant($mailType, 'Foo', 'foo');

        $params = [
            'raw' => Json::encode([
                'user_id' => 123,
                'email' => 'example@example.com',
                'list_code' => $mailType->code,
                'rtm_params' => [
                    'rtm_source' => 'test',
                    'rtm_medium' => 'engine',
                    'rtm_campaign' => 'cmp',
                    'rtm_content' => 'code',
                ]
            ])
        ];

        /** @var JsonApiResponse $response */
        $response = $this->handler->handle($params);
        $this->assertInstanceOf(JsonApiResponse::class, $response);
        $this->assertEquals(IResponse::S200_OK, $response->getCode());

        $isSubscribed = (bool) $this->userSubscriptionsRepository->getTable()->where([
            'user_id' => 123,
            'user_email' => 'example@example.com',
            'mail_type_id' => $mailType->id,
            'subscribed' => 1,
            'rtm_source' => 'test',
            'rtm_medium' => 'engine',
            'rtm_campaign' => 'cmp',
            'rtm_content' => 'code',
        ])->count('*');

        $this->assertTrue($isSubscribed);
    }

    public function testFailingSubscribeWithInvalidVariantCode()
    {
        $mailType = $this->createMailTypeWithCategory(
            "category1",
            "code1",
            "name1"
        );
        $variant = $this->createMailTypeVariant($mailType, 'Foo', 'foo');

        $params = [
            'raw' => Json::encode([
                'user_id' => 123,
                'email' => 'example@example.com',
                'list_code' => $mailType->code,
                'variant_code' => 'code_wrong',
            ])
        ];

        /** @var JsonApiResponse $response */
        $response = $this->handler->handle($params);
        $this->assertInstanceOf(JsonApiResponse::class, $response);
        $this->assertEquals(IResponse::S404_NotFound, $response->getCode());

        $isSubscribed = (bool) $this->userSubscriptionVariantsRepository->getTable()->where([
            'mail_user_subscription.user_id' => 123,
            'mail_user_subscription.user_email' => 'example@example.com',
            'mail_user_subscription.subscribed' => 1,
            'mail_type_variant_id' => $variant->id,
        ])->count('*');
        $this->assertFalse($isSubscribed);
    }
}
