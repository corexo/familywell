<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Core image storage with size variants. An uploaded image is stored on the given disk; an optional
// client-generated thumbnail is stored alongside it at "{path}.thumb.jpg". Serving then picks the
// variant a use case needs (a small thumb for cards/lists, the original for detail/full views).
//
// Variants are generated on the client (canvas) and uploaded, so this needs no server image extension.
// Modules should route image uploads through here instead of storing them ad-hoc, so every image gets
// a small variant for free. Old images with no thumb fall back to the original when a thumb is asked.
class StoredImage
{
    public static function disk(?string $disk = null): string
    {
        if ($disk !== null && $disk !== 'r2') {
            return $disk;
        }

        return filled(config('filesystems.disks.r2.key')) ? 'r2' : (config('filesystems.default') ?: 'local');
    }

    public static function thumbPath(string $path): string
    {
        return $path.'.thumb.jpg';
    }

    /** Store the main image (and its thumb, if provided) and return the main path. */
    public static function store(UploadedFile $main, ?UploadedFile $thumb, string $dir, ?string $disk = null): string
    {
        $d = self::disk($disk);
        $path = $main->store($dir, $d);
        if ($thumb) {
            Storage::disk($d)->put(self::thumbPath($path), (string) file_get_contents($thumb->getRealPath()));
        }

        return $path;
    }

    /** Delete the main image and its thumb (a no-op on a null path). */
    public static function delete(?string $path, ?string $disk = null): void
    {
        if (! $path) {
            return;
        }
        $d = self::disk($disk);
        Storage::disk($d)->delete([$path, self::thumbPath($path)]);
    }

    /**
     * Build the auth-gated URL for a stored image, keyed by its content so the browser can cache it
     * for a long time. `k` is a short hash of the stored path: store() names files randomly, so a
     * re-upload (new cover/photo) yields a new path and therefore a new URL, while the old one may
     * stay cached harmlessly. `v=thumb` picks the small variant. Every module MUST build image URLs
     * through here; a URL without `k` is served with the default no-cache headers (see response()).
     *
     * @param  mixed  $params  route parameters
     */
    public static function url(string $route, mixed $params, string $path, bool $thumb = false): string
    {
        $query = ($thumb ? 'v=thumb&' : '').'k='.substr(md5($path), 0, 8);

        return route($route, $params).'?'.$query;
    }

    /**
     * Serve a variant: 'thumb' returns the thumbnail when one exists, otherwise the original.
     * Cached a year, immutable, when the URL carries the content key from url(): the key changes with
     * the content, so a long cache never goes stale, and the round trip (auth + R2 fetch) is skipped
     * on every later render of the same card/list. `private` keeps Cloudflare and other shared caches
     * out, since these routes are per-user authorized.
     */
    public static function response(string $path, ?string $variant, ?string $disk = null): StreamedResponse
    {
        $d = Storage::disk(self::disk($disk));
        $headers = request()->query('k') ? ['Cache-Control' => 'private, max-age=31536000, immutable'] : [];
        if ($variant === 'thumb' && $d->exists(self::thumbPath($path))) {
            return $d->response(self::thumbPath($path), null, $headers);
        }

        return $d->response($path, null, $headers);
    }
}
