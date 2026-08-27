Visual C++ runtime — yahan teen files rakhein
=============================================

Kuch Windows machines par System32 mein PURANI Visual C++ runtime hoti hai
(version 14.0, yani VC++ 2015). Hamari PHP 8.2 build ko 14.29+ chahiye.
Aise PC par setup yeh error deta hai:

  'C:\Windows\SYSTEM32\VCRUNTIME140.dll' 14.0 is not compatible
   with this PHP build linked with 14.29

Aur uske baad php.exe bilkul nahi chalta — diagnostics mein saari
extensions "MISSING" aur "Application boot FAILED" dikhta hai.


Isse hamesha ke liye khatam karne ka tareeqa
--------------------------------------------

Kisi bhi aise Windows computer se jahan Visual C++ 2015-2022 x64
Redistributable pehle se install hai, C:\Windows\System32 se yeh TEEN
files copy kar ke IS folder mein daal dein:

    vcruntime140.dll
    vcruntime140_1.dll
    msvcp140.dll

(Tasdeeq: file par right-click -> Properties -> Details -> File version
 14.29 ya us se ziada honi chahiye.)

Uske baad har naye package mein yeh files khud shamil ho jayengi, aur
setup unhen php.exe ke saath rakh dega. Windows kisi exe ki DLL pehle
usi folder mein dhoondta hai, phir System32 — is liye System32 ki purani
copy be-asar ho jati hai.

Faida: customer ke PC par kuch install nahi karna parta. Yehi hamara
waada hai — "Nothing is installed on Windows".

Yeh folder khali bhi ho to setup chalta rahega; us soorat mein wo PC par
khud nayi copy dhoondega, aur na mile to customer ko vc_redist.x64.exe
install karne ko kahega.
