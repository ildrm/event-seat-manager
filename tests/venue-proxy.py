#!/usr/bin/env python3
"""Loopback-only disposable benchmark proxy; never a production HTTP server."""
import argparse
import asyncio
import itertools


async def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', type=int, default=8093)
    parser.add_argument('--backends', default='8099,8100')
    parser.add_argument('--connections', type=int, default=32)
    args = parser.parse_args()
    ports = itertools.cycle(int(p) for p in args.backends.split(','))
    permits = asyncio.Semaphore(args.connections)

    async def handle(reader, writer):
        backend = None
        try:
            header = await asyncio.wait_for(reader.readuntil(b'\r\n\r\n'), 30)
            if len(header) > 65536:
                raise ValueError('headers too large')
            lines = header.split(b'\r\n')
            length = 0
            forwarded = [lines[0]]
            for line in lines[1:]:
                name, _, value = line.partition(b':')
                if name.lower() == b'content-length':
                    length = int(value.strip())
                if line and name.lower() not in (b'connection', b'proxy-connection'):
                    forwarded.append(line)
            if not 0 <= length <= 134217728:
                raise ValueError('body too large')
            body = await asyncio.wait_for(reader.readexactly(length), 120) if length else b''
            async with permits:
                upstream, backend = await asyncio.open_connection('127.0.0.1', next(ports))
                backend.write(b'\r\n'.join(forwarded) + b'\r\nConnection: close\r\n\r\n' + body)
                await backend.drain()
                while chunk := await asyncio.wait_for(upstream.read(65536), 180):
                    writer.write(chunk)
                    await writer.drain()
        except (OSError, ValueError, asyncio.TimeoutError, asyncio.IncompleteReadError, asyncio.LimitOverrunError):
            if not writer.is_closing():
                writer.write(b'HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n')
        finally:
            if backend:
                backend.close()
            writer.close()
            await writer.wait_closed()

    server = await asyncio.start_server(handle, '127.0.0.1', args.port, limit=65537, backlog=2048)
    print(f'Loopback proxy :{args.port}; {args.connections} upstream slots', flush=True)
    async with server:
        await server.serve_forever()


if __name__ == '__main__':
    asyncio.run(main())
