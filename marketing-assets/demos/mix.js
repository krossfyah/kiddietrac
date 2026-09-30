// Mix narration + ducked music onto each paced clip, in simple fixed-length steps (this ffmpeg
// build is from 2018 and hangs on one long graph), then trim to the audio and write WebVTT captions.
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const FF = path.resolve(__dirname, '../vid/node_modules/@ffmpeg-installer/win32-x64/ffmpeg.exe');
const A = path.join(__dirname, 'audio');
const V = path.join(__dirname, 'video');
const T = path.join(__dirname, 'mixtmp');
const OUT = path.join(__dirname, 'out', 'demo-assets');
const CUES = JSON.parse(fs.readFileSync(path.join(A, 'cues.json'), 'utf8'));
const MUSIC_AT = { day: 0, ratios: 32, parent: 58 };   // a different section of the track per clip
fs.mkdirSync(T, { recursive: true });
const ff = (args) => execFileSync(FF, ['-y', '-loglevel', 'error', ...args], { stdio: 'inherit', timeout: 120000 });
const vtt = (t) => { const h = Math.floor(t / 3600), m = Math.floor(t % 3600 / 60), s = (t % 60).toFixed(3).padStart(6, '0'); return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + s; };

for (const name of ['day', 'ratios', 'parent']) {
  const cues = CUES[name], total = CUES[name + '_total'], d = String(total);
  // 1. Voice: each sentence placed at its cue, on a silent bed of exactly the clip's length.
  const vIn = ['-f', 'lavfi', '-t', d, '-i', 'anullsrc=r=48000:cl=stereo'];
  cues.forEach((c, i) => vIn.push('-i', path.join(A, `${name}-${i}.mp3`)));
  const vf = cues.map((c, i) => `[${i + 1}:a]aresample=48000,aformat=channel_layouts=stereo,adelay=${Math.round(c.start * 1000)}|${Math.round(c.start * 1000)}[s${i}]`);
  vf.push(`[0:a]${cues.map((c, i) => `[s${i}]`).join('')}amix=inputs=${cues.length + 1}:duration=first:dropout_transition=0,volume=${cues.length + 1}[vo]`);
  ff([...vIn, '-filter_complex', vf.join(';'), '-map', '[vo]', '-t', d, path.join(T, `${name}-voice.wav`)]);
  // 2. Music: its own section, low, faded in and out.
  ff(['-ss', String(MUSIC_AT[name]), '-t', d, '-i', path.join(A, 'music.mp3'),
    '-af', `aresample=48000,aformat=channel_layouts=stereo,volume=0.22,afade=t=in:st=0:d=0.8,afade=t=out:st=${(total - 1.6).toFixed(2)}:d=1.6`,
    '-t', d, path.join(T, `${name}-music.wav`)]);
  // 3. Duck the music under the voice, then mix.
  ff(['-i', path.join(T, `${name}-music.wav`), '-i', path.join(T, `${name}-voice.wav`),
    '-filter_complex', '[1:a]asplit=2[k][vv];[0:a][k]sidechaincompress=threshold=0.02:ratio=10:attack=15:release=350[m];[vv][m]amix=inputs=2:duration=first:dropout_transition=0,volume=2[a]',
    '-map', '[a]', '-t', d, path.join(T, `${name}-mix.wav`)]);
  // 4. Standard web loudness.
  ff(['-i', path.join(T, `${name}-mix.wav`), '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11', '-ar', '48000', '-ac', '2', '-t', d, path.join(T, `${name}-final.wav`)]);
  // 5. Picture trimmed to the audio, muxed.
  const scale = name === 'parent' ? 'scale=390:-2' : 'scale=1280:-2';
  ff(['-i', path.join(V, name + '-vo.webm'), '-i', path.join(T, `${name}-final.wav`),
    '-vf', `${scale},fps=25,format=yuv420p`, '-map', '0:v', '-map', '1:a', '-t', d,
    '-c:v', 'libx264', '-crf', name === 'parent' ? '25' : '27', '-preset', 'slow', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart',
    path.join(OUT, `clip-${name}.mp4`)]);
  ff(['-ss', '1.2', '-i', path.join(OUT, `clip-${name}.mp4`), '-frames:v', '1', '-q:v', '3', path.join(OUT, `clip-${name}.jpg`)]);
  fs.writeFileSync(path.join(OUT, `clip-${name}.en.vtt`),
    'WEBVTT\n\n' + cues.map((c, i) => `${i + 1}\n${vtt(c.start)} --> ${vtt(c.end + 0.3)}\n${c.text}\n`).join('\n'));
  console.log(name, total + 's', fs.statSync(path.join(OUT, `clip-${name}.mp4`)).size, 'bytes');
}
