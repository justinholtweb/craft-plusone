{{-- Plus One harness: /plusone/account-blade — the Blade side of the template API. --}}
<p id="user">{{ $currentUser ? $currentUser->username : 'guest' }}</p>
<p id="available">{{ $plusone->isAvailable() ? 'yes' : 'no' }}</p>
<p id="providers">{{ collect($plusone->providers())->map(fn ($p) => $p['handle'].'='.$p['name'])->join(',') }}</p>
<p id="loginUrl">{{ $plusone->loginUrl('plusone-fake', '/plusone/account') }}</p>
<div id="buttons">{{ $plusone->buttons('/plusone/account') }}</div>
<ul id="accounts">@foreach ($plusone->accounts() as $a)<li>{{ $a['handle'] }}:{{ $a['connected'] ? 'connected' : 'not' }}:{{ $a['canDisconnect'] ? 'can' : 'cannot' }}</li>@endforeach</ul>
<p id="needsPassword">{{ $plusone->needsPassword() ? 'yes' : 'no' }}</p>
<div id="connectForm">{{ $plusone->connectForm('plusone-fake', '/plusone/account') }}</div>
<div id="connectKc">{{ $plusone->connectForm('plusone-kc', '/plusone/account') }}</div>
<div id="disconnectForm">{{ $plusone->disconnectForm('plusone-fake', '/plusone/account') }}</div>
@php($m = $plusone->message())
<p id="message">{{ $m ? $m['type'].':'.$m['message'] : '' }}</p>
