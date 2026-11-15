# SPDX-License-Identifier: AGPL-3.0-or-later
"""Private Docker archive inspection without extraction, config execution or content logging."""
import hashlib,tarfile
from pathlib import PurePosixPath
class Refused(RuntimeError):pass

def inventory(path,root):
    out={};total=0
    with tarfile.open(path,'r|*')as archive:
        for member in archive:
            name=PurePosixPath(member.name)
            if name.is_absolute() or '..'in name.parts or not name.parts or name.parts[0]!=root or str(name)in out:raise Refused('Archive path refused')
            if len(out)>=20000 or member.size<0 or member.size>134217728:raise Refused('Archive bound exceeded')
            total+=member.size
            if total>1073741824:raise Refused('Archive aggregate bound exceeded')
            if member.isdir():kind='directory';digest=None
            elif member.isfile():
                kind='file';h=hashlib.sha256();stream=archive.extractfile(member);size=0
                while True:
                    data=stream.read(65536)
                    if not data:break
                    h.update(data);size+=len(data)
                if size!=member.size:raise Refused('Archive file incomplete')
                digest=h.hexdigest()
            else:raise Refused('Archive link or special file refused')
            out[str(name)]={'type':kind,'sha256':digest,'size':member.size,'mode':member.mode,'uid':member.uid,'gid':member.gid}
    if root not in out or out[root]['type']!='directory':raise Refused('Archive root missing')
    return out

def changed(before,after,allowed):
    if not isinstance(allowed,dict):raise Refused('Archive allowance invalid')
    expected=dict(before)
    for key,value in allowed.items():
        if value is None:expected.pop(key,None)
        else:expected[key]=value
    if expected!=after:raise Refused('Complete archive preimage changed')
    return True
