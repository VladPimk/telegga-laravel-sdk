<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\MediaData;
use Telegga\Laravel\Exceptions\MediaException;
use Telegga\Laravel\Exceptions\TeleggaApiException;

it('uploads a media file with a multipart request without losing response fields', function (): void {
    $contents = 'file-content';
    $filename = 'photo.jpg';

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/media' => Http::response([
            'media_id' => 'media-1',
            'mime_type' => 'image/jpeg',
            'size' => 12,
            'filename' => $filename,
            'new_api_field' => 'new-value',
        ], 201),
    ]);

    $media = app(TeleggaInterface::class)->uploadMedia(
        contents: $contents,
        filename: $filename,
    );

    expect($media)
        ->toBeInstanceOf(MediaData::class)
        ->and($media->media_id)
        ->toBe('media-1')
        ->and($media->mime_type)
        ->toBe('image/jpeg')
        ->and($media->raw()->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request) use ($contents, $filename): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/media'
            && $request->isMultipart()
            && $request->hasFile(
                name: 'file',
                value: $contents,
                filename: $filename,
            );
    });
});

it('gets media metadata without losing new response fields', function (): void {
    $mediaId = 'e97d00ad-0000-4000-8000-000000000000';

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/media/{$mediaId}" => Http::response([
            'media_id' => $mediaId,
            'mime_type' => 'image/jpeg',
            'size' => 123456,
            'filename' => 'photo.jpg',
            'new_api_field' => 'new-value',
        ]),
    ]);

    $media = app(TeleggaInterface::class)->getMedia(
        mediaId: $mediaId,
    );

    expect($media)
        ->toBeInstanceOf(MediaData::class)
        ->and($media->media_id)
        ->toBe($mediaId)
        ->and($media->filename)
        ->toBe('photo.jpg')
        ->and($media->raw()->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request) use ($mediaId): bool {
        return $request->method() === 'GET'
            && $request->url() === "https://api.telegga.net/api/v1/media/{$mediaId}";
    });
});

it('does not send a request with empty media contents', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->uploadMedia(
            contents: '',
            filename: 'photo.jpg',
        );
    } catch (MediaException $exception) {
        expect($exception->getMessage())
            ->toBe('Media file contents cannot be empty.')
            ->and($exception->filename)
            ->toBe('photo.jpg')
            ->and($exception->mediaId)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Expected a MediaException.');
});

it('does not send a request without a media filename', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->uploadMedia(
            contents: 'file-content',
            filename: '   ',
        );
    } catch (MediaException $exception) {
        expect($exception->getMessage())
            ->toBe('Media filename cannot be empty.')
            ->and($exception->filename)
            ->toBe('   ')
            ->and($exception->mediaId)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Expected a MediaException.');
});

it('does not send a media file larger than fifty megabytes', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->uploadMedia(
            contents: str_repeat(string: 'a', times: 50 * 1024 * 1024 + 1),
            filename: 'large.bin',
        );
    } catch (MediaException $exception) {
        expect($exception->getMessage())
            ->toBe('Media file exceeds the maximum size of 50 MB.')
            ->and($exception->filename)
            ->toBe('large.bin')
            ->and($exception->mediaId)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Expected a MediaException.');
});

it('wraps an API error when uploading a media file', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/media' => Http::response([
            'error' => [
                'code' => 'invalid_request',
                'message' => 'Media file is invalid.',
            ],
        ], 400),
    ]);

    try {
        app(TeleggaInterface::class)->uploadMedia(
            contents: 'file-content',
            filename: 'photo.jpg',
        );
    } catch (MediaException $exception) {
        expect($exception->filename)
            ->toBe('photo.jpg')
            ->and($exception->mediaId)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('invalid_request')
            ->and($exception->getPrevious()?->status)
            ->toBe(400);

        return;
    }

    test()->fail('Expected a MediaException.');
});

it('does not send a request with an empty media identifier', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->getMedia(mediaId: '   ');
    } catch (MediaException $exception) {
        expect($exception->getMessage())
            ->toBe('Media identifier cannot be empty.')
            ->and($exception->mediaId)
            ->toBe('   ')
            ->and($exception->filename)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Expected a MediaException.');
});

it('wraps an API error when getting media metadata', function (): void {
    $mediaId = 'e97d00ad-0000-4000-8000-000000000000';

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/media/{$mediaId}" => Http::response([
            'error' => [
                'code' => 'not_found',
                'message' => 'Media was not found.',
            ],
        ], 404),
    ]);

    try {
        app(TeleggaInterface::class)->getMedia(
            mediaId: $mediaId,
        );
    } catch (MediaException $exception) {
        expect($exception->mediaId)
            ->toBe($mediaId)
            ->and($exception->filename)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('not_found')
            ->and($exception->getPrevious()?->status)
            ->toBe(404);

        return;
    }

    test()->fail('Expected a MediaException.');
});

it('rejects a successful media response with invalid JSON', function (): void {
    $mediaId = 'e97d00ad-0000-4000-8000-000000000000';

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/media/{$mediaId}" => Http::response(
            body: 'not-json',
            status: 200,
        ),
    ]);

    try {
        app(TeleggaInterface::class)->getMedia(
            mediaId: $mediaId,
        );
    } catch (MediaException $exception) {
        expect($exception->mediaId)
            ->toBe($mediaId)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('invalid_response');

        return;
    }

    test()->fail('Expected a MediaException.');
});
