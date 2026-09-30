<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotificationRead extends Model
{
    protected $table = 'user_notification_reads';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['user_id', 'notice_key', 'read_at'];
}
