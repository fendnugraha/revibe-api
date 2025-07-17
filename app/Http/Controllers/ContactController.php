<?php

namespace App\Http\Controllers;

use App\Http\Resources\DataResource;
use App\Models\Contact;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $contacts = Contact::orderBy('name', 'asc')
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', '%' . $search . '%')
                    ->orWhere('phone_number', 'like', '%' . $search . '%')
                    ->orWhere('address', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            })
            ->paginate(10)
            ->onEachSide(0);
        return new DataResource($contacts, true, "Successfully fetched contacts");
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:60',
            'type' => 'required|string|max:15',
            'phone_number' => 'nullable|string|max:15',
            'address' => 'nullable|string|max:160',
            'description' => 'nullable|string|max:255'
        ]);

        $contact = Contact::create([
            'name' => $request['name'],
            'type' => $request['type'],
            'phone_number' => $request['phone_number'],
            'address' => $request['address'],
            'description' => $request['description'] ?? 'General Contact'
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Contact created successfully',
            'data' => $contact
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $contact = Contact::find($id);
        $contact->update($request->all());
        return response()->json([
            'status' => 'success',
            'message' => 'Contact updated successfully',
            'data' => $contact
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Contact $contact)
    {
        $transactionsExist = $contact->transactions()->exists();
        $financesExist = $contact->finances()->exists();

        if ($transactionsExist || $financesExist || $contact->id === 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete contact with existing transactions or finances'
            ], 400);
        }

        $contact->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Contact deleted successfully'
        ], 200);
    }

    public function getAllContacts()
    {
        $contacts = Contact::orderBy('name', 'asc')->get();
        return new DataResource($contacts, true, "Successfully fetched contacts");
    }
}
