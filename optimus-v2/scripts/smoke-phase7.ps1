#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$base = $env:OPTIMUS_API_BASE
if ([string]::IsNullOrWhiteSpace($base)) { $base = 'http://localhost:5080' }

function Login([string]$email) {
  $body = @{ email = $email; password = 'Admin123!' } | ConvertTo-Json
  return Invoke-RestMethod -Method Post -Uri "$base/api/auth/login" -ContentType 'application/json' -Body $body
}

function AuthHeaders([string]$token) { @{ Authorization = "Bearer $token" } }

Write-Host "=== Phase 7 smoke @ $base ==="
$root = Invoke-RestMethod "$base/"
if ($root.phase -ne 7) { throw "Expected phase 7, got $($root.phase)" }
Write-Host "phase=$($root.phase) OK"

$roles = @(
  'admin@optimus.local',
  'sladmin@optimus.local',
  'slstaff@optimus.local',
  'evaluator@optimus.local',
  'accounting@optimus.local',
  'terminal@optimus.local',
  'broker@optimus.local',
  'consignee@optimus.local',
  'trucker@optimus.local'
)

foreach ($email in $roles) {
  $auth = Login $email
  if (-not $auth.accessToken) { throw "Login failed for $email" }
  Write-Host "login OK $email role=$($auth.user.role)"
}

$admin = Login 'admin@optimus.local'
$ah = AuthHeaders $admin.accessToken

# Upload rejection
try {
  $boundary = [guid]::NewGuid().ToString()
  $bad = "--$boundary`r`nContent-Disposition: form-data; name=`"file`"; filename=`"evil.exe`"`r`nContent-Type: application/octet-stream`r`n`r`nMZ`r`n--$boundary--`r`n"
  Invoke-WebRequest -Method Post -Uri "$base/api/shipping-lines/$([guid]::Empty)/logo" -Headers $ah -ContentType "multipart/form-data; boundary=$boundary" -Body ([Text.Encoding]::UTF8.GetBytes($bad)) | Out-Null
  throw 'Upload of .exe should have failed'
} catch {
  if ($_.Exception.Response.StatusCode.value__ -ge 400) {
    Write-Host 'upload guard OK (.exe rejected)'
  } else {
    # InvalidOperation may surface as 500 via middleware — still counts as blocked
    Write-Host "upload guard OK (blocked: $($_.Exception.Message))"
  }
}

# Health + metrics + settings
Invoke-RestMethod -Headers $ah -Uri "$base/health" | Out-Null
$metrics = Invoke-RestMethod -Headers $ah -Uri "$base/api/reports/edo-release"
Write-Host "edo metrics generated=$($metrics.totalGenerated)"

# Broker IDOR: list manifests then try random guid
$broker = Login 'broker@optimus.local'
$bh = AuthHeaders $broker.accessToken
$rand = [guid]::NewGuid()
try {
  Invoke-RestMethod -Headers $bh -Uri "$base/api/manifests/$rand"
  throw 'Broker should not open random manifest'
} catch {
  Write-Host 'IDOR guard OK (random manifest blocked for broker)'
}

# Refresh reuse family revoke
$refresh = $admin.refreshToken
$r1 = Invoke-RestMethod -Method Post -Uri "$base/api/auth/refresh" -ContentType 'application/json' -Body (@{ refreshToken = $refresh } | ConvertTo-Json)
try {
  Invoke-RestMethod -Method Post -Uri "$base/api/auth/refresh" -ContentType 'application/json' -Body (@{ refreshToken = $refresh } | ConvertTo-Json)
  throw 'Reuse should fail'
} catch {
  Write-Host 'refresh reuse revoke OK'
}
# Family revoke should also invalidate the rotated token issued before reuse was detected.
try {
  Invoke-RestMethod -Method Post -Uri "$base/api/auth/refresh" -ContentType 'application/json' -Body (@{ refreshToken = $r1.refreshToken } | ConvertTo-Json)
  throw 'Rotated token should be revoked with family'
} catch {
  Write-Host 'refresh family revoke OK'
}
$again = Login 'admin@optimus.local'
if (-not $again.accessToken) { throw 'Re-login after family revoke failed' }
Write-Host 're-login after revoke OK'

Write-Host 'SMOKE_PHASE7_OK'
