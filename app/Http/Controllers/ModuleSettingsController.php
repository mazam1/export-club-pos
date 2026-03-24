<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ModuleSettingsController extends BaseController
{
    public function get_modules_info()
    {
        return $this->sendResponse([], 'Modules info');
    }

    public function update_status_module(Request $request)
    {
        return $this->sendResponse([], 'Module status updated');
    }

    public function upload_module(Request $request)
    {
        return $this->sendResponse([], 'Module uploaded');
    }

    public function get_modules_enabled()
    {
        return $this->sendResponse([], 'Modules enabled');
    }
}
