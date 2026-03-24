<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;

class EmployeeSessionController extends BaseController
{
    public function attendance_by_employee(Request $request, $id)
    {
        return $this->sendResponse([], 'Not implemented');
    }
}
