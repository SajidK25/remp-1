<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SegmentRule extends Model
{
    use HasFactory;

    protected $casts = [
        'fields' => 'json',
        'flags' => 'json',
        'timespan' => 'integer',
    ];

    protected $attributes = [
        'fields' => '[]',
        'flags' => '[]',
    ];

    protected $fillable = [
        'id',
        'timespan',
        'count',
        'event_category',
        'event_action',
        'segment_id',
        'operator',
        'fields',
        'flags',
    ];

    public function segment()
    {
        return $this->belongsTo(Segment::class);
    }
}
