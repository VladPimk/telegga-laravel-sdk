<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http\Responses;

use Symfony\Component\HttpFoundation\Response;
use Telegga\Laravel\Webhooks\WebhookProcessingStatus;

enum WebhookResponseCode: string
{
    case Accepted = 'accepted';
    case Connected = 'connected';
    case AlreadyConnected = 'already_connected';
    case Duplicate = 'duplicate';
    case EventIdConflict = 'event_id_conflict';
    case ConnectionNotFound = 'connection_not_found';
    case ConnectionDeleted = 'connection_deleted';
    case BotNotFound = 'bot_not_found';
    case BotDeleted = 'bot_deleted';
    case BotMismatch = 'bot_mismatch';
    case Unauthorized = 'unauthorized';
    case InvalidRequest = 'invalid_request';
    case UnsupportedEvent = 'unsupported_event';
    case Internal = 'internal';

    /**
     * Create an HTTP response code from a webhook processing result.
     */
    public static function fromProcessingStatus(WebhookProcessingStatus $status): self
    {
        return match ($status) {
            WebhookProcessingStatus::Connected => self::Connected,
            WebhookProcessingStatus::AlreadyConnected => self::AlreadyConnected,
            WebhookProcessingStatus::Duplicate => self::Duplicate,
            WebhookProcessingStatus::EventIdConflict => self::EventIdConflict,
            WebhookProcessingStatus::ConnectionNotFound => self::ConnectionNotFound,
            WebhookProcessingStatus::ConnectionDeleted => self::ConnectionDeleted,
            WebhookProcessingStatus::BotNotFound => self::BotNotFound,
            WebhookProcessingStatus::BotDeleted => self::BotDeleted,
            WebhookProcessingStatus::BotMismatch => self::BotMismatch,
        };
    }

    /**
     * Determine whether the HTTP response is successful.
     */
    public function successful(): bool
    {
        return in_array($this, [
            self::Accepted,
            self::Connected,
            self::AlreadyConnected,
            self::Duplicate,
        ], true);
    }

    /**
     * Get the response HTTP status.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Accepted,
            self::Connected,
            self::AlreadyConnected,
            self::Duplicate => Response::HTTP_OK,
            self::Unauthorized => Response::HTTP_UNAUTHORIZED,
            self::ConnectionNotFound,
            self::ConnectionDeleted,
            self::BotNotFound,
            self::BotDeleted => Response::HTTP_NOT_FOUND,
            self::EventIdConflict,
            self::BotMismatch => Response::HTTP_CONFLICT,
            self::InvalidRequest,
            self::UnsupportedEvent => Response::HTTP_BAD_REQUEST,
            self::Internal => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * Get the response message.
     */
    public function message(): string
    {
        return match ($this) {
            self::Accepted => 'Webhook accepted.',
            self::Connected => 'Telegram connection marked as connected.',
            self::AlreadyConnected => 'Telegram connection is already connected.',
            self::Duplicate => 'Webhook event has already been processed.',
            self::EventIdConflict => 'Webhook event_id is already assigned to a different connection or event.',
            self::ConnectionNotFound => 'Telegram connection was not found for the provided external_id.',
            self::ConnectionDeleted => 'Telegram connection for the provided external_id has been deleted.',
            self::BotNotFound => 'Telegram bot assigned to the connection was not found.',
            self::BotDeleted => 'Telegram bot assigned to the connection has been deleted.',
            self::BotMismatch => 'Telegram connection is assigned to a different bot.',
            self::Unauthorized => 'Invalid webhook token.',
            self::InvalidRequest => 'Webhook request is invalid.',
            self::UnsupportedEvent => 'Webhook event is not supported.',
            self::Internal => 'Webhook could not be processed.',
        };
    }
}
