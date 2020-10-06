<?php

namespace WrapLr\LaravelExplorer\App\Http\Controllers;

use Session;
use Storage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use WrapLr\LaravelExplorer\App\Http\Controllers\BaseController;
use WrapLr\LaravelExplorer\App\WlrleFile;

class FileController extends BaseController
{
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors(),
            ], 400);
        }

        $currentDirectory = $this->getCurrentWorkingDirectory();

        if (!$currentDirectory) {
            return response()->json([
                'message' => 'Wrong current working directory!',
            ], 400);
        }

        // upload path, relative to base_directory/upload_directory
        $path = Carbon::now()->format('Y/m/d');

        // base directory
        $base = Storage::disk('public')->path(config('wlrle.upload_directory'));

        // full path
        $full = $base.'/'.$path;

        // create it, if any
        if (!is_dir($full)) {
            mkdir($full, 0777, true);
        }

        // get uploaded file
        $file = $request->file('file');

        // check for unique name
        $fileName = $this->getUniqueFileName($currentDirectory, $file->getClientOriginalName());

        // create model
        $wlrleFile = new WlrleFile([
            'name' => $fileName,
            'mime_type' => mime_content_type($file->getPathName()),
            'path' => $path,
            'extension' => strtolower($file->getClientOriginalExtension()),
            'size' => $file->getSize(),
        ]);

        if (in_array($wlrleFile->mime_type, config('wlrle.valid_file_mime_types'))) {
            // create new file in database
            $currentDirectory->files()->save($wlrleFile);

            // new phisical name
            $storageName = base_convert($wlrleFile->id, 10, 36).($file->getClientOriginalExtension() == '' ? '' : '.').$file->getClientOriginalExtension();

            // move file to path
            if (!$file->move($full, $storageName)) {
                // move error
                $wlrleFile->delete();

                // return server error
                return response()->json([
                    'message' => 'Server error (can not move the file from temp folder to '.$full.'/'.$storageName.')!',
                ], 400);
            }
        } else {
            // move to temp path
            if ($file->move($base, $file->getFilename())) {
                // temp path
                $temp = $base.'/'.$file->getFilename();

                // delete temp file
                unlink($temp);
            }

            // return invalid mim type error
            return response()->json([
                'message' => 'Invalid mime type (<strong>'.$file->getClientOriginalName().'</strong>: '.$wlrleFile->mime_type.')!',
            ], 400);
        }

        // return success
        return response()->json([], 200);
    }

    public function rename(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors(),
            ], 400);
        }

        $currentDirectory = $this->getCurrentWorkingDirectory();

        if (!$currentDirectory) {
            return response()->json([
                'message' => 'Wrong current working directory!',
            ], 400);
        }

        // get change directory
        $file = WlrleFile::whereId($id)->first();

        if (!$file) {
            return response()->json([
                'message' => 'Wrong file selected!',
            ], 400);
        }

        // get all names
        $files = $currentDirectory->files->where('id', '!=', $file->id)->pluck('name')->all();

        // name already exists (even if it's own name)
        if (in_array($request->name, $files)) {
            return response()->json([
                'name' => $file->name,
                'message' => 'Could not rename file from '.$file->name.' to '.$request->name,
            ], 400);
        }

        // set name
        $file->name = $request->name;

        // save it
        $file->save();

        // success
        return response()->json([
            'name' => $file->name,
        ], 200);
    }
}
