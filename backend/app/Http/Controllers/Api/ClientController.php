<?php

namespace App\Http\Controllers\Api;

use App\Events\DirectoryChanged;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Clients (level 1). Only admins create, rename and archive them. */
class ClientController extends Controller
{
    /** Admins see every client (archived too); members see the active clients they belong to. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $clients = Client::query()
            ->unless(Access::canManageClient($user), fn ($q) => $q
                ->whereNull('archived_at')
                ->whereHas('members', fn ($m) => $m->whereKey($user->id)))
            ->orderBy('name')
            ->get();

        return ClientResource::collection($clients);
    }

    public function show(Request $request, Client $client): ClientResource
    {
        abort_unless(Access::canSeeClient($request->user(), $client), 404);

        return new ClientResource($client);
    }

    public function store(Request $request): ClientResource
    {
        abort_unless(Access::canManageClient($request->user()), 403, 'Only admins can create clients.');
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'The name is required.');

        $client = Client::create(['name' => $name, 'created_by' => $request->user()->id]);
        Realtime::send(new DirectoryChanged);

        return new ClientResource($client);
    }

    public function update(Request $request, Client $client): ClientResource
    {
        abort_unless(Access::canManageClient($request->user()), 403, 'Only admins can rename clients.');
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'The name is required.');

        $client->update(['name' => $name]);
        Realtime::send(new DirectoryChanged);

        return new ClientResource($client);
    }

    /** Archive or restore. Archived clients (and all their projects) disappear for everyone but admins. */
    public function setArchived(Request $request, Client $client): ClientResource
    {
        abort_unless(Access::canManageClient($request->user()), 403, 'Only admins can archive clients.');
        $data = $request->validate(['archived' => ['required', 'boolean']]);

        $client->archived_at = $data['archived'] ? now() : null;
        $client->save();
        Realtime::send(new DirectoryChanged);

        return new ClientResource($client);
    }
}
