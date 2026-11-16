# SPDX-License-Identifier: AGPL-3.0-or-later
"""Bounded child I/O and private checkpoints; never expose command arguments/output in exceptions."""
from pathlib import Path
import hashlib,json,os,selectors,subprocess,time
class Refused(RuntimeError):pass

def private_directory(path):
    path=Path(path)
    if path.exists() or path.is_symlink():raise Refused('Private checkpoint already exists')
    path.mkdir(mode=0o700)
    if path.stat().st_mode&0o777!=0o700:raise Refused('Private checkpoint mode changed')
    return path

def private_write(path,data):
    path=Path(path)
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb')as f:
            f.write(data);f.flush();os.fsync(f.fileno())
            if os.fstat(f.fileno()).st_mode&0o777!=0o600:raise Refused('Private checkpoint mode changed')
        if path.read_bytes()!=data:raise Refused('Private checkpoint readback failed')
        directory=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(directory)
        finally:os.close(directory)
    except BaseException:
        # An uncertain complete/partial checkpoint is retained for inspection, never blindly replaced.
        raise
    return hashlib.sha256(data).hexdigest()

def child(command,limit=1048576,timeout=120,output_path=None,input_data=None):
    if not isinstance(command,list) or not all(isinstance(x,str)and'\x00'not in x for x in command):raise Refused('Child command malformed')
    stream=None
    if output_path is not None:
        fd=os.open(output_path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600);stream=os.fdopen(fd,'wb')
    process=None;selection=selectors.DefaultSelector();data=bytearray();size=0;errors=0;started=time.monotonic()
    try:
        if input_data is not None and (not isinstance(input_data,bytes) or len(input_data)>1048576):raise Refused('Child input bound exceeded')
        process=subprocess.Popen(command,stdin=subprocess.PIPE if input_data is not None else subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if input_data is not None:
            os.set_blocking(process.stdin.fileno(),False);selection.register(process.stdin,selectors.EVENT_WRITE);pending=memoryview(input_data)
        for pipe in (process.stdout,process.stderr):os.set_blocking(pipe.fileno(),False);selection.register(pipe,selectors.EVENT_READ)
        while selection.get_map():
            if time.monotonic()-started>timeout:raise Refused('Child deadline exceeded')
            for key,_ in selection.select(.05):
                if key.fileobj is process.stdin:
                    if pending:pending=pending[os.write(key.fd,pending[:65536]):]
                    if not pending:selection.unregister(process.stdin);process.stdin.close()
                    continue
                block=os.read(key.fd,65536)
                if not block:selection.unregister(key.fileobj);continue
                if key.fileobj is process.stderr:
                    errors+=len(block)
                    if errors>16384:raise Refused('Child error output exceeded')
                    continue
                size+=len(block)
                if size>limit:raise Refused('Child output exceeded')
                if stream is None:data.extend(block)
                else:stream.write(block)
        if process.wait(timeout=max(.1,timeout-(time.monotonic()-started)))!=0:raise Refused('Child failed')
        if stream is not None:
            stream.flush();os.fsync(stream.fileno())
            if os.fstat(stream.fileno()).st_mode&0o777!=0o600:raise Refused('Private checkpoint mode changed')
            return {'bytes':size,'private':True}
        return bytes(data)
    except BaseException:
        if process is not None and process.poll() is None:
            process.terminate()
            try:process.wait(timeout=2)
            except subprocess.TimeoutExpired:process.kill();process.wait(timeout=2)
        raise
    finally:
        selection.close()
        if process is not None:
            process.stdout.close();process.stderr.close()
            if process.stdin is not None and not process.stdin.closed:process.stdin.close()
        if stream is not None:stream.close()

def container_metadata(ident):
    # Fixed selected fields only: no Config.Env, full inspect or arbitrary HostConfig serialization.
    fields=['.Id','.Name','.Image','.State.Running','.State.Status','(index .Config.Labels "forms.guard.test")','.NetworkSettings.Ports','.Mounts','.NetworkSettings.Networks','.HostConfig.ExtraHosts','.State.Pid','.State.StartedAt','.State.ExitCode','(index .Config "StopSignal")','.HostConfig.RestartPolicy.Name','(index .HostConfig "Tmpfs")']
    raw=child(['docker','inspect','--format','\n'.join('{{json '+x+'}}'for x in fields),ident],limit=65536)
    try:values=[json.loads(x)for x in raw.splitlines()]
    except (ValueError,UnicodeError):raise Refused('Container metadata malformed')from None
    if len(values)!=len(fields):raise Refused('Container metadata incomplete')
    return dict(zip(['id','name','image','running','status','label','ports','mounts','networks','extra_hosts','pid','started_at','exit_code','stop_signal','restart_policy','tmpfs'],values))

SQL="SELECT json_build_object('superuser',(SELECT rolsuper FROM pg_roles WHERE rolname=current_user),'foreign_client_sessions',(SELECT COUNT(*) FROM pg_stat_activity WHERE backend_type='client backend' AND pid<>pg_backend_pid()),'prepared_transactions',(SELECT COUNT(*) FROM pg_prepared_xacts));"
def postgres_writers(db_id):
    # Password stays in the existing DB process environment; shell text and argv contain no value.
    fixed='PGPASSWORD="$POSTGRES_PASSWORD" exec psql -X --no-psqlrc --set=ON_ERROR_STOP=1 --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --tuples-only --no-align --command='+"'"+SQL.replace("'","'\\''")+"'"
    raw=child(['docker','exec',db_id,'sh','-c',fixed],limit=4096,timeout=15)
    try:return json.loads(raw)
    except (ValueError,UnicodeError):raise Refused('Database session proof malformed')from None

def database_checkpoint(db_id,path,custom):
    flags='--format=custom'if custom else'--data-only --no-owner --no-privileges'
    fixed='PGPASSWORD="$POSTGRES_PASSWORD" exec pg_dump '+flags+' --username="$POSTGRES_USER" --dbname="$POSTGRES_DB"'
    return child(['docker','exec',db_id,'sh','-c',fixed],limit=536870912,timeout=120,output_path=path)
