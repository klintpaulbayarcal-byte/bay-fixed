param(
    [string]$ApiBase = 'http://localhost/bay',
    [string]$Origin = 'http://localhost:5173',
    [string]$HealthToken = ''
)

function Fail($msg) {
    Write-Error $msg
    exit 1
}

Write-Host "API base: $ApiBase"

# Health check
$htUrl = "$ApiBase/health.php"
if ($HealthToken -ne '') { $htUrl = "$htUrl?token=$HealthToken" }
Write-Host "Calling health: $htUrl"
try {
    $h = Invoke-WebRequest -UseBasicParsing -Uri $htUrl -Method GET -Headers @{ Origin = $Origin } -TimeoutSec 15
    $json = $h.Content | ConvertFrom-Json
    if (-not $json.ok) { Fail "Health check failed: $($h.Content)" }
    Write-Host 'Health OK'
}
catch {
    Fail "Health request failed: $($_.Exception.Message)"
}

# Preflight check for auth_api.php
Write-Host 'Running CORS preflight (OPTIONS) for auth_api.php'
try {
    $opt = Invoke-WebRequest -UseBasicParsing -Uri "$ApiBase/auth_api.php" -Method OPTIONS -Headers @{ Origin = $Origin; 'Access-Control-Request-Method' = 'POST' } -TimeoutSec 10 -ErrorAction Stop
    if (-not $opt.Headers['Access-Control-Allow-Origin']) { Fail 'CORS preflight missing Allow-Origin' }
    Write-Host 'CORS preflight OK' 
}
catch {
    Fail "CORS preflight failed: $($_.Exception.Message)"
}

# Login test (uses test account)
Write-Host 'Attempting login with test account (jai)'
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginBody = @{ action = 'login'; username = 'jai'; password = '212121' } | ConvertTo-Json
try {
    $login = Invoke-WebRequest -UseBasicParsing -Uri "$ApiBase/auth_api.php" -Method POST -Headers @{ Origin = $Origin; 'Content-Type' = 'application/json' } -Body $loginBody -WebSession $session -TimeoutSec 15
    $loginJson = $login.Content | ConvertFrom-Json
    if (-not $loginJson.success) { Fail "Login failed: $($login.Content)" }
    Write-Host 'Login OK, role=' $loginJson.role
}
catch {
    Fail "Login request failed: $($_.Exception.Message)"
}

# Protected endpoint test: order_status_api.php?mode=queue
Write-Host 'Testing protected endpoint order_status_api.php?mode=queue'
try {
    $q = Invoke-WebRequest -UseBasicParsing -Uri "$ApiBase/order_status_api.php?mode=queue" -Headers @{ Origin = $Origin } -WebSession $session -Method GET -TimeoutSec 15
    $qjson = $q.Content | ConvertFrom-Json
    if (-not $qjson.success) { Fail "Protected endpoint returned failure: $($q.Content)" }
    Write-Host 'Protected endpoint OK'
}
catch {
    Fail "Protected endpoint failed: $($_.Exception.Message)"
}

# Products list
Write-Host 'Fetching products list'
try {
    $p = Invoke-WebRequest -UseBasicParsing -Uri "$ApiBase/products_api.php" -Headers @{ Origin = $Origin } -Method GET -TimeoutSec 15
    $pjson = $p.Content | ConvertFrom-Json
    if (-not $pjson.success) { Fail "Products endpoint failed: $($p.Content)" }
    Write-Host "Products OK, count=" $pjson.count
}
catch {
    Fail "Products request failed: $($_.Exception.Message)"
}

Write-Host 'Post-deploy smoke tests completed successfully.'
exit 0
