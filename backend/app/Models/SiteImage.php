<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** One admin-uploaded site image (key → stored file). No row = the built-in design default. */
class SiteImage extends Model
{
    public const DISK = 'public';

    protected $fillable = ['key', 'path', 'files', 'updated_by'];

    protected function casts(): array
    {
        return ['files' => 'array'];
    }

    /** @return list<string> every stored file of this image */
    public function allPaths(): array
    {
        return array_values(array_unique(array_filter([$this->path, ...array_values($this->files ?? [])])));
    }

    public function deleteFiles(): void
    {
        Storage::disk(self::DISK)->delete($this->allPaths());
    }
}
