using System;
using System.Net;
using System.Text;
using System.Threading.Tasks;
using DPUruNet; // from U.are.U SDK

namespace UareUHelper
{
    internal static class Program
    {
        private static Reader _reader;

        private static void Main()
        {
            Console.Title = "U.are.U Helper (port 48081)";
            var http = new HttpListener();

            // Bind BOTH localhost names
            http.Prefixes.Add("http://localhost:48081/");
            http.Prefixes.Add("http://127.0.0.1:48081/");

            try
            {
                http.Start();
            }
            catch (HttpListenerException ex)
            {
                Console.WriteLine("HttpListener failed to start.");
                Console.WriteLine("Run these once in an elevated PowerShell:");
                Console.WriteLine("  netsh http add urlacl url=http://localhost:48081/ user=\"BUILTIN\\Users\"");
                Console.WriteLine("  netsh http add urlacl url=http://127.0.0.1:48081/ user=\"BUILTIN\\Users\"");
                Console.WriteLine($"\nDetails: {ex.Message}");
                Pause();
                return;
            }

            Console.WriteLine("READY on http://localhost:48081");
            Console.WriteLine("POST /enroll  -> { template_b64, format:\"ANSI-378\" }");
            Console.WriteLine("POST /ping    -> { ok: true }");
            Console.WriteLine("\nPress ENTER to quit.");

            _ = Task.Run(() => Loop(http));

            Console.ReadLine();
            try { http.Stop(); } catch { }
            CloseReader();
        }

        private static async Task Loop(HttpListener http)
        {
            while (http.IsListening)
            {
                HttpListenerContext ctx = null;
                try { ctx = await http.GetContextAsync().ConfigureAwait(false); }
                catch { break; }
                if (ctx == null) continue;
                _ = Task.Run(() => Handle(ctx));
            }
        }

        private static void Handle(HttpListenerContext ctx)
        {
            try
            {
                // CORS
                ctx.Response.AddHeader("Access-Control-Allow-Origin", "*");
                ctx.Response.AddHeader("Access-Control-Allow-Headers", "Content-Type");
                if (ctx.Request.HttpMethod == "OPTIONS")
                {
                    ctx.Response.StatusCode = 204;
                    ctx.Response.Close();
                    return;
                }

                var path = ctx.Request.Url.AbsolutePath.ToLowerInvariant();
                if (ctx.Request.HttpMethod == "POST" && path == "/ping")
                {
                    WriteJson(ctx, 200, "{\"ok\":true}");
                    return;
                }

                if (ctx.Request.HttpMethod == "POST" && path == "/enroll")
                {
                    var fmd = CaptureFmdAnsi378();
                    if (fmd == null)
                    {
                        WriteJson(ctx, 500, "{\"error\":\"capture_failed\"}");
                        return;
                    }
                    var b64 = Convert.ToBase64String(fmd);
                    WriteJson(ctx, 200, "{\"template_b64\":\"" + b64 + "\",\"format\":\"ANSI-378\"}");
                    return;
                }

                WriteJson(ctx, 404, "{\"error\":\"not_found\"}");
            }
            catch (Exception ex)
            {
                try { WriteJson(ctx, 500, "{\"error\":\"" + Escape(ex.Message) + "\"}"); } catch { }
            }
        }

        private static void WriteJson(HttpListenerContext ctx, int code, string json)
        {
            var buf = Encoding.UTF8.GetBytes(json);
            ctx.Response.ContentType = "application/json; charset=utf-8";
            ctx.Response.StatusCode = code;
            try { using var s = ctx.Response.OutputStream; s.Write(buf, 0, buf.Length); } catch { }
        }

        private static string Escape(string s) => (s ?? "").Replace("\\", "\\\\").Replace("\"", "\\\"");

        // -------- Fingerprint capture using U.are.U SDK --------

        private static bool OpenReader()
        {
            if (_reader != null) return true;

            var readers = ReaderCollection.GetReaders();
            if (readers == null || readers.Count == 0)
            {
                Console.WriteLine("No U.are.U reader detected.");
                return false;
            }

            _reader = readers[0];
            var rc = _reader.Open(Reader.Priority.COOPERATIVE);
            if (rc != Constants.ResultCode.DP_SUCCESS)
            {
                Console.WriteLine("Reader open failed: " + rc);
                CloseReader();
                return false;
            }
            return true;
        }

        private static void CloseReader()
        {
            try { _reader?.Dispose(); } catch { }
            _reader = null;
        }

        private static byte[] CaptureFmdAnsi378()
        {
            try
            {
                if (!OpenReader()) return null;

                Fid fid;
                Reader.CaptureQuality qual;
                var rc = _reader.Capture(
                    Fid.Format.ANSI_381_2004,
                    Reader.ImageProcessing.IMG_PROC_DEFAULT,
                    7000, // 7s timeout
                    out fid,
                    out qual
                );

                if (rc != Constants.ResultCode.DP_SUCCESS || fid == null)
                {
                    Console.WriteLine("Capture failed: " + rc);
                    return null;
                }
                if (qual != Reader.CaptureQuality.GOOD)
                {
                    Console.WriteLine("Capture quality: " + qual);
                }

                Fmd fmd;
                rc = FeatureExtraction.CreateFmdFromFid(fid, Fmd.Format.ANSI_378_2004, out fmd);
                if (rc != Constants.ResultCode.DP_SUCCESS || fmd == null)
                {
                    Console.WriteLine("Feature extraction failed: " + rc);
                    return null;
                }

                return fmd.Bytes;
            }
            catch (Exception ex)
            {
                Console.WriteLine("Capture error: " + ex.Message);
                return null;
            }
        }

        private static void Pause()
        {
            Console.WriteLine("\nPress ENTER to close...");
            Console.ReadLine();
        }
    }
}
