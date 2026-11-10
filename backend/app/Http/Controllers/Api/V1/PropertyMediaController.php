<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class PropertyMediaController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $data = $request->validate([
            'images' => ['sometimes', 'array', 'max:4'],
            'images.*' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
            ],
            'videos' => ['sometimes', 'array', 'max:2'],
            'videos.*' => [
                'required',
                'file',
                'mimetypes:video/mp4,video/quicktime,video/webm',
                'max:102400',
            ],
        ]);

        $images = $request->file('images', []);
        $videos = $request->file('videos', []);

        if (
            count($images) + $property->media()->where('media_type', 'image')->count() > 4
        ) {
            throw ValidationException::withMessages([
                'images' => ['A property can have at most 4 images in total.'],
            ]);
        }

        if (
            count($videos) + $property->media()->where('media_type', 'video')->count() > 2
        ) {
            throw ValidationException::withMessages([
                'videos' => ['A property can have at most 2 videos in total.'],
            ]);
        }

        // Store new property media in the working public storage1 directory.
        $disk = 'public_media';

        $storedPaths = [];

        try {
            $media = DB::transaction(function () use (
                $images,
                $videos,
                $property,
                $request,
                $disk,
                &$storedPaths
            ) {
                $created = [];

                foreach (['image' => $images, 'video' => $videos] as $type => $files) {
                    foreach ($files as $file) {
                        $path = $file->store(
                            "properties/{$property->id}/{$type}s",
                            $disk
                        );

                        if (!$path) {
                            throw new \RuntimeException('File upload failed.');
                        }

                        $storedPaths[] = $path;

                        $created[] = PropertyMedia::create([
                            'property_id' => $property->id,
                            'uploaded_by' => $request->user()->id,
                            'media_type' => $type,
                            'disk' => $disk,
                            'file_path' => $path,
                            'original_filename' => $file->getClientOriginalName(),
                            'mime_type' => $file->getMimeType(),
                            'file_size' => $file->getSize(),
                            'sort_order' => $property->media()->count(),
                            'is_primary' => $type === 'image'
                                && $property->media()
                                    ->where('media_type', 'image')
                                    ->count() === 0,
                        ]);
                    }
                }

                return $created;
            });
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk($disk)->delete($path);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'Property media uploaded successfully.',
            'data' => collect($media)->map(fn (PropertyMedia $item) => [
                'id' => $item->id,
                'media_type' => $item->media_type,
                'url' => Storage::disk($item->disk)->url($item->file_path),
                'original_filename' => $item->original_filename,
                'mime_type' => $item->mime_type,
                'file_size' => $item->file_size,
                'sort_order' => $item->sort_order,
                'is_primary' => $item->is_primary,
            ]),
        ], 201);
    }

    public function destroy(
        Property $property,
        PropertyMedia $media
    ): JsonResponse {
        $this->authorize('update', $property);

        if ((int) $media->property_id !== (int) $property->id) {
            abort(404, 'Media not found for this property.');
        }

        $disk = $media->disk;
        $path = $media->file_path;
        $mediaId = $media->id;
        $mediaType = $media->media_type;
        $wasPrimaryImage = $mediaType === 'image' && $media->is_primary;

        DB::transaction(function () use (
            $property,
            $media,
            $wasPrimaryImage
        ) {
            $media->delete();

            if ($wasPrimaryImage) {
                $nextImage = $property->media()
                    ->where('media_type', 'image')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first();

                if ($nextImage) {
                    $nextImage->update(['is_primary' => true]);
                }
            }
        });

        if (!Storage::disk($disk)->delete($path)) {
            \Illuminate\Support\Facades\Log::warning(
                'Property media file cleanup failed.',
                [
                    'property_id' => $property->id,
                    'media_id' => $mediaId,
                    'file_path' => $path,
                ]
            );

            return response()->json([
                'message' => 'Media record deleted, but file cleanup failed.',
                'file_cleanup_failed' => true,
            ], 500);
        }

        return response()->json([
            'message' => 'Property media deleted successfully.',
            'data' => [
                'id' => $mediaId,
                'property_id' => $property->id,
                'media_type' => $mediaType,
            ],
        ]);
    }
    public function setPrimary(
    Request $request,
    Property $property,
    PropertyMedia $media
): JsonResponse {
    $this->authorize('update', $property);

    // Ensure the image belongs to this property.
    abort_unless(
     (int) $media->property_id === (int) $property->id,
        404,
        'Media not found for this property.'
    );

    // Only images can be primary.
    if ($media->media_type !== 'image') {
        return response()->json([
            'success' => false,
            'message' => 'Only images can be set as primary.',
        ], 422);
    }

    DB::transaction(function () use ($property, $media) {
        // Remove primary status from all existing images.
        $property->media()
            ->where('media_type', 'image')
            ->update(['is_primary' => false]);

        // Set the selected image as primary.
        $media->update(['is_primary' => true]);
    });

    return response()->json([
        'success' => true,
        'message' => 'Primary image updated successfully.',
        'data' => $property->media()
            ->orderBy('sort_order')
            ->get(),
    ]);
}
}
