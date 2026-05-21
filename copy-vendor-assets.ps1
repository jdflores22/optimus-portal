# PowerShell script to copy vendor assets from node_modules to public directory

Write-Host "Copying vendor assets from node_modules to public..." -ForegroundColor Green

# Create vendor directory in public
$vendorDir = "public/vendor"
if (-not (Test-Path $vendorDir)) {
    New-Item -ItemType Directory -Path $vendorDir -Force | Out-Null
}

# Copy Notyf
Write-Host "Copying Notyf..." -ForegroundColor Yellow
$notyfDir = "$vendorDir/notyf"
if (-not (Test-Path $notyfDir)) {
    New-Item -ItemType Directory -Path $notyfDir -Force | Out-Null
}
Copy-Item "node_modules/notyf/notyf.min.css" -Destination $notyfDir -Force
Copy-Item "node_modules/notyf/notyf.min.js" -Destination $notyfDir -Force

# Copy Lodash
Write-Host "Copying Lodash..." -ForegroundColor Yellow
$lodashDir = "$vendorDir/lodash"
if (-not (Test-Path $lodashDir)) {
    New-Item -ItemType Directory -Path $lodashDir -Force | Out-Null
}
Copy-Item "node_modules/lodash/lodash.min.js" -Destination $lodashDir -Force

# Copy ApexCharts
Write-Host "Copying ApexCharts..." -ForegroundColor Yellow
$apexDir = "$vendorDir/apexcharts"
if (-not (Test-Path $apexDir)) {
    New-Item -ItemType Directory -Path $apexDir -Force | Out-Null
}
Copy-Item "node_modules/apexcharts/dist/apexcharts.min.js" -Destination $apexDir -Force

Write-Host "`nVendor assets copied successfully!" -ForegroundColor Green
Write-Host "`nFiles copied to:" -ForegroundColor Cyan
Write-Host "  - public/vendor/notyf/notyf.min.css"
Write-Host "  - public/vendor/notyf/notyf.min.js"
Write-Host "  - public/vendor/lodash/lodash.min.js"
Write-Host "  - public/vendor/apexcharts/apexcharts.min.js"
Write-Host "`nNext steps:" -ForegroundColor Yellow
Write-Host "1. Upload the public/vendor/ folder to Hostinger"
Write-Host "2. Update templates/base.html.twig to use /vendor/ instead of /node_modules/"
