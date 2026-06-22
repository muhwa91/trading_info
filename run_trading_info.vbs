Set objShell = CreateObject("WScript.Shell")
Set objFSO = CreateObject("Scripting.FileSystemObject")

' 스크립트(.vbs) 자신이 위치한 폴더를 기준 경로로 사용 → 프로젝트를 옮겨도 경로가 깨지지 않음
strBase = objFSO.GetParentFolderName(WScript.ScriptFullName)
strBackend = strBase & "\backend"
strFrontend = strBase & "\frontend"

Function IsPortOpen(port)
    Dim intResult
    ' netstat 으로 해당 포트 사용 여부 확인 (창 숨김, 종료까지 대기)
    intResult = objShell.Run("cmd.exe /c netstat -ano | findstr /c "":"" " & port, 0, True)
    ' findstr 가 매치하면 종료코드 0
    If intResult = 0 Then
        IsPortOpen = True
    Else
        IsPortOpen = False
    End If
End Function

' 1. Backend API (Port 8000)
If Not IsPortOpen(8000) Then
    objShell.Run "cmd.exe /c cd /d """ & strBackend & """ && php artisan serve --port=8000", 0, False
End If

' 2. WebSocket Server (Port 8080)
If Not IsPortOpen(8080) Then
    objShell.Run "cmd.exe /c cd /d """ & strBackend & """ && php artisan agent:serve", 0, False
End If

' 3. Frontend Dev Server (Port 5173)
If Not IsPortOpen(5173) Then
    objShell.Run "cmd.exe /c cd /d """ & strFrontend & """ && npm run dev", 0, False
End If

' 서버 기동 대기 (3초)
WScript.Sleep 3000

' 기본 브라우저로 대시보드 열기 (일반 창)
objShell.Run "http://localhost:5173", 1, False
