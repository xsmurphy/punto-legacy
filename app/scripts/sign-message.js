/*
 * JavaScript client-side example using jsrsasign
 */

// #########################################################
// #             WARNING   WARNING   WARNING               #
// #########################################################
// #                                                       #
// # This file is intended for demonstration purposes      #
// # only.                                                 #
// #                                                       #
// # It is the SOLE responsibility of YOU, the programmer  #
// # to prevent against unauthorized access to any signing #
// # functions.                                            #
// #                                                       #
// # Organizations that do not protect against un-         #
// # authorized signing will be black-listed to prevent    #
// # software piracy.                                      #
// #                                                       #
// # -QZ Industries, LLC                                   #
// #                                                       #
// #########################################################

/**
 * Depends:
 *     - jsrsasign-latest-all-min.js
 *     - qz-tray.js
 *
 * Steps:
 *
 *     1. Include jsrsasign 8.0.4 into your web page
 *        <script src="https://cdn.rawgit.com/kjur/jsrsasign/c057d3447b194fa0a3fdcea110579454898e093d/jsrsasign-all-min.js"></script>
 *
 *     2. Update the privateKey below with contents from private-key.pem
 *
 *     3. Include this script into your web page
 *        <script src="path/to/sign-message.js"></script>
 *
 *     4. Remove or comment out any other references to "setSignaturePromise"
 */
var privateKey = '-----BEGIN PRIVATE KEY-----' +
'MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQCbTvCL2E9rTQZK' +
'hEf/hZztn0GndtsHcAglW1yVGN7ILGmh1dtK1eUlR2oTP42yy03XaC9xwLfxdpF/' +
'c24WrfFOnKQ6q+m+wvEH24ccPziojlSApqYi+LjGK2dnCsq9Gq6lTzQEJTj+z7Uw' +
'2n/Rn+cDQyFukYlepxjgYzO+sOxxvLCPmct/M9QUwbVY0Ij6iv/ln6r3JBRfAj8A' +
'8wYVLilBrQIdcTYof8KsM7va+BhSRxDDFLKeCoNmnjxFGrNX6hbDs7xdS1hcEFz3' +
'bPrUhIvq1LaQX7/CICJdq2pIBii5j2ku/v18pQiJQuErN0Y8uPLTk/hbKvEWqb/6' +
'6m3G31pFAgMBAAECggEADJpkElEgJsK2ITtpVU68LJtJGmQeg5S/kHqAwZemUoOq' +
'IcgnNsQzR2prbPryDoGJhFKv0PEU7DsVNQzCsQP2Ck1TVXCIdCLBKQRTS0NFH4aH' +
'THZZkopxAiHZDwdU6vIcnI0YGUMFBEaKO1fr2fC6MC1VK/IS/fOc5O6f7xhP463N' +
'+478CNWA8JE9WiTLxNC2eY0eateAsW6HXceBpU943isQxNMlcU6ejVZ/Io/a9icL' +
'JxN2xuaeF2NvOmGWm2abnWx1TBJO/s/Jd7HIHzpMyz/pnsjwTVTOujBD3OmTT0tJ' +
'T2B2qDDZr+oD+1A7uxwPVGQ2xChw7xDLc51xvk+D4QKBgQDbH+P3Q09Puo7UKjoX' +
'g56Dxr/fcDq8Pg1SqamYBNTUrAKMTUpjUF/y1wMrrpPwUqRonLiSk68iW/7XZpJu' +
'RZtPjjmRxcjzo44e+VjodAA07ueWl0YRuYvm0AtVTOOIaXLv6Ue8PIdGhCU7Jrqk' +
'bMLJ0x5mkeBeXd9WTGZxft914QKBgQC1ccgdxUiK8Y3fcVJAJMrZCOIt5SRPMMt8' +
'2tlZOs+2cEqWjQq8Px7eMQHM27QY4intws8IfxFuKIalfSZTS9gZBtqVnwBf4JSd' +
'4WznYQOcVXYppqPpG40qc/stV2Qg+Z41Jt8ZEsu0JOA7WBOxc74oHRYMOgpLgQzQ' +
'138YLQfo5QKBgBk4X22bIqrDhyLmRU9lh74VBwp5iVkXL0NfYbSsga6EqbpqPvCV' +
'VKXHl4bUjhRv/ppHx3qfYt3qhrdWB+6HNmv+q6Oahxl7rqTkABapG0j8Yk1T1e2+' +
'VFrZgSRtOBcARAlW6TnCIbO9C+f1+i9okTbXhL07dv6FgWoWWwgfGSshAoGARK4g' +
'CJzPm8BZanWzo5IJsmN5cdPljZAzxjv0v6DSVQVmRlx27tCZt5MnUkrrfevF4Ti3' +
'M0kd6OuwI94ebrMrxjVg8fewpZoVxzk4BtEjE78JrjRkoO0L30Dtl7kXrp+t8gKX' +
'uh7yOmsm8W+ibK4aEYcI/HHPycq8diTL9/O7pb0CgYAwCIymPRe3TQV9GNHRr/4O' +
'3Boxs3SpD64ILUzsmSrbO3SYxSkadAcA9fS1QHFvywJ0hPIpZjAAUzXpBSDc/sVN' +
'xu6esUpqWYImdj079FukuqnBFzbJ7DNHpFp3FSrlbRRvnLQLVFcGSG36TynvcBBU' +
'vkcL7qAGhaQ+210bEhAbRA==' +
'-----END PRIVATE KEY-----';

qz.security.setSignatureAlgorithm("SHA512"); // Since 2.1
qz.security.setSignaturePromise(function(toSign) {
    return function(resolve, reject) {
        try {
            var pk = KEYUTIL.getKey(privateKey);
            var sig = new KJUR.crypto.Signature({"alg": "SHA512withRSA"});  // Use "SHA1withRSA" for QZ Tray 2.0 and older
            sig.init(pk); 
            sig.updateString(toSign);
            var hex = sig.sign();
            console.log("DEBUG: \n\n" + stob64(hextorstr(hex)));
            resolve(stob64(hextorstr(hex)));
        } catch (err) {
            console.error(err);
            reject(err);
        }
    };
});