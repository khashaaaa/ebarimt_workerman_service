#!/bin/bash

for i in $(seq -f "%05g" 1 3); do
   if [ -f "/app/${i}/PosService" ]; then
       /app/${i}/PosService &
   fi
done

wait

#!/bin/bash

# Start the first service in the background

# /app/00001/PosService &
# /app/00002/PosService &
# /app/00003/PosService &
# /app/00004/PosService &
# /app/00005/PosService &
# /app/00006/PosService &
# /app/00007/PosService &
# /app/00008/PosService &
# /app/00009/PosService &
# /app/00010/PosService &
# /app/00011/PosService &
# /app/00012/PosService &
# /app/00013/PosService &
# /app/00014/PosService &
# /app/00015/PosService &
# /app/00016/PosService &
# /app/00017/PosService &
# /app/00018/PosService &
# /app/00019/PosService &
# /app/00020/PosService &
# /app/00021/PosService &
# /app/00022/PosService &
# /app/00023/PosService &
# /app/00024/PosService &
# /app/00025/PosService &
# /app/00026/PosService &
# /app/00027/PosService &
# /app/00028/PosService &
# /app/00029/PosService &
# /app/00030/PosService &
# /app/00031/PosService &
# /app/00032/PosService &
# /app/00033/PosService &
# /app/00034/PosService &
# /app/00035/PosService &
# /app/00036/PosService &
# /app/00037/PosService &
# /app/00038/PosService &
# /app/00039/PosService &
# /app/00040/PosService &
# /app/00041/PosService &
# /app/00042/PosService &
# /app/00043/PosService &
# /app/00044/PosService &
# /app/00045/PosService &
# /app/00046/PosService &
# /app/00047/PosService &
# /app/00048/PosService &
# /app/00049/PosService &
# /app/00050/PosService &
# /app/00051/PosService &
# /app/00052/PosService &
# /app/00053/PosService &
# /app/00054/PosService &
# /app/00055/PosService &
# /app/00056/PosService &
# /app/00057/PosService &
# /app/00058/PosService &
# /app/00059/PosService &
# /app/00060/PosService &
# /app/00061/PosService &
# /app/00062/PosService &
# /app/00063/PosService &
# /app/00064/PosService &
# /app/00065/PosService &
# /app/00066/PosService &
# /app/00067/PosService &
# /app/00068/PosService &
# /app/00069/PosService &
# /app/00070/PosService &
# /app/00071/PosService &
# /app/00072/PosService &
# /app/00073/PosService &
# /app/00074/PosService &
# /app/00075/PosService &
# /app/00076/PosService &
# /app/00077/PosService &
# /app/00078/PosService &
# /app/00079/PosService &
# /app/00080/PosService &
# /app/00081/PosService &
# /app/00082/PosService &
# /app/00083/PosService &
# /app/00084/PosService &
# /app/00085/PosService &
# /app/00086/PosService &
# /app/00087/PosService &
# /app/00088/PosService &
# /app/00089/PosService &
# /app/00090/PosService &
# /app/00091/PosService &
# /app/00092/PosService &
# /app/00093/PosService &
# /app/00094/PosService &
# /app/00095/PosService &
# /app/00096/PosService &
# /app/00097/PosService &
# /app/00098/PosService &
# /app/00099/PosService &
# /app/00100/PosService &
# /app/00101/PosService &
# /app/00102/PosService &
# /app/00103/PosService &
# /app/00104/PosService &
# /app/00105/PosService &
# /app/00106/PosService &
# /app/00107/PosService &
# /app/00108/PosService &
# /app/00109/PosService &
# /app/00110/PosService &
# /app/00111/PosService &
# /app/00112/PosService &
# /app/00113/PosService &
# /app/00114/PosService &
# /app/00115/PosService &
# /app/00116/PosService &
# /app/00117/PosService &
# /app/00118/PosService &
# /app/00119/PosService &
# /app/00120/PosService &
# /app/00121/PosService &
# /app/00122/PosService &
# /app/00123/PosService &
# /app/00124/PosService &
# /app/00125/PosService &
# /app/00126/PosService &
# /app/00127/PosService &
# /app/00128/PosService &
# /app/00129/PosService &
# /app/00130/PosService &
# /app/00131/PosService &
# /app/00132/PosService &
# /app/00133/PosService &
# /app/00134/PosService &
# /app/00135/PosService &
# /app/00136/PosService &
# /app/00137/PosService &
# /app/00138/PosService &
# /app/00139/PosService &
# /app/00140/PosService &
# /app/00141/PosService &
# /app/00142/PosService &
# /app/00143/PosService &
# /app/00144/PosService &
# /app/00145/PosService &
# /app/00146/PosService &
# /app/00147/PosService &
# /app/00148/PosService &
# /app/00149/PosService &
# /app/00150/PosService &
# /app/00151/PosService &
# /app/00152/PosService &
# /app/00153/PosService &
# /app/00154/PosService &
# /app/00155/PosService &
# /app/00156/PosService &
# /app/00157/PosService &
# /app/00158/PosService &
# /app/00159/PosService &
# /app/00160/PosService &
# /app/00161/PosService &
# /app/00162/PosService &
# /app/00163/PosService &
# /app/00164/PosService &
# /app/00165/PosService &
# /app/00166/PosService &
# /app/00167/PosService &
# /app/00168/PosService &
# /app/00169/PosService &
# /app/00170/PosService &
# /app/00171/PosService &
# /app/00172/PosService &
# /app/00173/PosService &
# /app/00174/PosService &
# /app/00175/PosService &
# /app/00176/PosService &
# /app/00177/PosService &
# /app/00178/PosService &
# /app/00179/PosService &
# /app/00180/PosService &
# /app/00181/PosService &
# /app/00182/PosService &
# /app/00183/PosService &
# /app/00184/PosService &
# /app/00185/PosService &
# /app/00186/PosService &
# /app/00187/PosService &
# /app/00188/PosService &
# /app/00189/PosService &
# /app/00190/PosService &
# /app/00191/PosService &
# /app/00192/PosService &
# /app/00193/PosService &
# /app/00194/PosService &
# /app/00195/PosService &
# /app/00196/PosService &
# /app/00197/PosService &
# /app/00198/PosService &
# /app/00199/PosService &
# /app/00200/PosService &
# /app/00201/PosService &
# /app/00202/PosService &
# /app/00203/PosService &
# /app/00204/PosService &
# /app/00205/PosService &
# /app/00206/PosService &
# /app/00207/PosService &
# /app/00208/PosService &
# /app/00209/PosService &
# /app/00210/PosService &
# /app/00211/PosService &
# /app/00212/PosService &
# /app/00213/PosService &
# /app/00214/PosService &
# /app/00215/PosService &
# /app/00216/PosService &
# /app/00217/PosService &
# /app/00218/PosService &
# /app/00219/PosService &
# /app/00220/PosService &
# /app/00221/PosService &
# /app/00222/PosService &
# /app/00223/PosService &
# /app/00224/PosService &
# /app/00225/PosService &
# /app/00226/PosService &
# /app/00227/PosService &
# /app/00228/PosService &
# /app/00229/PosService &
# /app/00230/PosService &
# /app/00231/PosService &
# /app/00232/PosService &
# /app/00233/PosService &
# /app/00234/PosService &
# /app/00235/PosService &
# /app/00236/PosService &
# /app/00237/PosService &
# /app/00238/PosService &
# /app/00239/PosService &
# /app/00240/PosService &
# /app/00241/PosService &
# /app/00242/PosService &
# /app/00243/PosService &
# /app/00244/PosService &
# /app/00245/PosService &
# /app/00246/PosService &
# /app/00247/PosService &
# /app/00248/PosService &
# /app/00249/PosService &
# /app/00250/PosService &
# /app/00251/PosService &
# /app/00252/PosService &
# /app/00253/PosService &
# /app/00254/PosService &
# /app/00255/PosService &
# /app/00256/PosService &
# /app/00257/PosService &
# /app/00258/PosService &
# /app/00259/PosService &
# /app/00260/PosService &
# /app/00261/PosService &
# /app/00262/PosService &
# /app/00263/PosService &
# /app/00264/PosService &
# /app/00265/PosService &
# /app/00266/PosService &
# /app/00267/PosService &
# /app/00268/PosService &
# /app/00269/PosService &
# /app/00270/PosService &
# /app/00271/PosService &
# /app/00272/PosService &
# /app/00273/PosService &
# /app/00274/PosService &
# /app/00275/PosService &
# /app/00276/PosService &
# /app/00277/PosService &
# /app/00278/PosService &
# /app/00279/PosService &
# /app/00280/PosService &
# /app/00281/PosService &
# /app/00282/PosService &
# /app/00283/PosService &
# /app/00284/PosService &
# /app/00285/PosService &
# /app/00286/PosService &
# /app/00287/PosService &
# /app/00288/PosService &
# /app/00289/PosService &
# /app/00290/PosService &
# /app/00291/PosService &
# /app/00292/PosService &
# /app/00293/PosService &
# /app/00294/PosService &
# /app/00295/PosService &
# /app/00296/PosService &
# /app/00297/PosService &
# /app/00298/PosService &
# /app/00299/PosService &
# /app/00300/PosService &
# /app/00301/PosService &
# /app/00302/PosService &
# /app/00303/PosService &
# /app/00304/PosService &
# /app/00305/PosService &
# /app/00306/PosService &
# /app/00307/PosService &
# /app/00308/PosService &
# /app/00309/PosService &
# /app/00310/PosService &
# /app/00311/PosService &
# /app/00312/PosService &
# /app/00313/PosService &
# /app/00314/PosService &
# /app/00315/PosService &
# /app/00316/PosService &
# /app/00317/PosService &
# /app/00318/PosService &
# /app/00319/PosService &
# /app/00320/PosService &
# /app/00321/PosService &
# /app/00322/PosService &
# /app/00323/PosService &
# /app/00324/PosService &
# /app/00325/PosService &
# /app/00326/PosService &
# /app/00327/PosService &
# /app/00328/PosService &
# /app/00329/PosService &
# /app/00330/PosService &
# /app/00331/PosService &
# /app/00332/PosService &
# /app/00333/PosService &
# /app/00334/PosService &
# /app/00335/PosService &
# /app/00336/PosService &
# /app/00337/PosService &
# /app/00338/PosService &
# /app/00339/PosService &
# /app/00340/PosService &
# /app/00341/PosService &
# /app/00342/PosService &
# /app/00343/PosService &
# /app/00344/PosService &
# /app/00345/PosService &
# /app/00346/PosService &
# /app/00347/PosService &
# /app/00348/PosService &
# /app/00349/PosService &
# /app/00350/PosService &
# /app/00351/PosService &
# /app/00352/PosService &
# /app/00353/PosService &
# /app/00354/PosService &
# /app/00355/PosService &
# /app/00356/PosService &
# /app/00357/PosService &
# /app/00358/PosService &
# /app/00359/PosService &
# /app/00360/PosService &
# /app/00361/PosService &
# /app/00362/PosService &
# /app/00363/PosService &
# /app/00364/PosService &
# /app/00365/PosService &
# /app/00366/PosService &
# /app/00367/PosService &
# /app/00368/PosService &
# /app/00369/PosService &
# /app/00370/PosService &
# /app/00371/PosService &
# /app/00372/PosService &
# /app/00373/PosService &
# /app/00374/PosService &
# /app/00375/PosService &
# /app/00376/PosService &
# /app/00377/PosService &
# /app/00378/PosService &
# /app/00379/PosService &
# /app/00380/PosService &
# /app/00381/PosService &
# /app/00382/PosService &
# /app/00383/PosService &
# /app/00384/PosService &
# /app/00385/PosService &
# /app/00386/PosService &
# /app/00387/PosService &
# /app/00388/PosService &
# /app/00389/PosService &
# /app/00390/PosService &
# /app/00391/PosService &
# /app/00392/PosService &
# /app/00393/PosService &
# /app/00394/PosService &
# /app/00395/PosService &
# /app/00396/PosService &
# /app/00397/PosService &
# /app/00398/PosService &
# /app/00399/PosService &
# /app/00400/PosService &
# /app/00401/PosService &
# /app/00402/PosService &
# /app/00403/PosService &
# /app/00404/PosService &
# /app/00405/PosService &
# /app/00406/PosService &
# /app/00407/PosService &
# /app/00408/PosService &
# /app/00409/PosService &
# /app/00410/PosService &
# /app/00411/PosService &
# /app/00412/PosService &
# /app/00413/PosService &
# /app/00414/PosService &
# /app/00415/PosService &
# /app/00416/PosService &
# /app/00417/PosService &
# /app/00418/PosService &
# /app/00419/PosService &
# /app/00420/PosService &
# /app/00421/PosService &
# /app/00422/PosService &
# /app/00423/PosService &
# /app/00424/PosService &
# /app/00425/PosService &
# /app/00426/PosService &
# /app/00427/PosService &
# /app/00428/PosService &
# /app/00429/PosService &
# /app/00430/PosService &
# /app/00431/PosService &
# /app/00432/PosService &
# /app/00433/PosService &
# /app/00434/PosService &
# /app/00435/PosService &
# /app/00436/PosService &
# /app/00437/PosService &
# /app/00438/PosService &
# /app/00439/PosService &
# /app/00440/PosService &
# /app/00441/PosService &
# /app/00442/PosService &
# /app/00443/PosService &
# /app/00444/PosService &
# /app/00445/PosService &
# /app/00446/PosService &
# /app/00447/PosService &
# /app/00448/PosService &
# /app/00449/PosService &
# /app/00450/PosService &


# wait

