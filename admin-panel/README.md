# Intimora Local Admin v2

Runs on your PC only (127.0.0.1). Uploads videos to Telegram via MTProto.

    npm install
    npm start          (or run.bat on Windows)
    http://localhost:3000

## Setup
Copy `config.example.json` to `config.json` and fill apiId / apiHash / session / channels.
`config.json` holds your Telegram session = full access to your account. Never share or commit it.

## What's new
- Jobs panel: live % bar, speed, ETA, stage, and log for every upload and re-process
- Stages: check -> convert (moov to start) -> upload video -> thumbnail -> metadata
- Jobs run on the server, so closing the browser tab does not stop them
- Re-process fixed (download by correct message id, metadata channel updated, old message deleted last)
- Sync fixed (pagination + video detection); synced videos show "Not checked" until re-processed
- Temp files go to ./work and are cleaned automatically

## Important: teleproto version
This project needs **teleproto 1.230.1** (pinned). Newer 1.232/1.233 cannot read Telegram's current Channel object
and fail with "TypeNotFoundError ... channels.GetChannels". If you ever see that error run:

    npm install teleproto@1.230.1 --save-exact
