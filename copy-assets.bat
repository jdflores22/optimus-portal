@echo off
echo Copying JavaScript assets from assets/js to public/js...
copy "assets\js\*" "public\js\"
echo.
echo Copying CSS assets from assets/css to public/css...
copy "assets\css\*" "public\css\"
echo.
echo Copying Notyf library to public/node_modules/notyf...
if not exist "public\node_modules\notyf" mkdir "public\node_modules\notyf"
copy "node_modules\notyf\notyf.min.css" "public\node_modules\notyf\"
copy "node_modules\notyf\notyf.min.js" "public\node_modules\notyf\"
echo.
echo Done! All assets have been copied.
pause