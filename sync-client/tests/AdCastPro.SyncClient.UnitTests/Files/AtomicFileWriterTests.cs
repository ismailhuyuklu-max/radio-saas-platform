using System.Security.Cryptography;
using System.Text;
using AdCastPro.SyncClient.Core.Configuration;
using AdCastPro.SyncClient.Infrastructure.Files;
using FluentAssertions;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using Xunit;

namespace AdCastPro.SyncClient.UnitTests.Files;

/// <summary>
/// BROADCAST GARANTİSİ TESTLERİ — kritik path, %100 coverage hedefi.
/// </summary>
public class AtomicFileWriterTests : IDisposable
{
    private readonly string _baseDir;
    private readonly AtomicFileWriter _writer;
    private readonly SyncClientOptions _options;

    public AtomicFileWriterTests()
    {
        _baseDir = Path.Combine(Path.GetTempPath(), "AdCastProTest_" + Guid.NewGuid().ToString("N")[..8]);
        Directory.CreateDirectory(_baseDir);
        _options = new SyncClientOptions
        {
            Folders = new FolderPaths
            {
                Temp = Path.Combine(_baseDir, "temp"),
                News = Path.Combine(_baseDir, "haber"),
            }
        };
        _writer = new AtomicFileWriter(Options.Create(_options), NullLogger<AtomicFileWriter>.Instance);
    }

    public void Dispose()
    {
        try { Directory.Delete(_baseDir, recursive: true); } catch { }
    }

    [Fact]
    public async Task WriteAtomic_CheckcumDogruysa_HedefeAtomikTasir()
    {
        var content = Encoding.UTF8.GetBytes("AdCastPro broadcast safe content");
        var expectedSha = Sha256Hex(content);

        using var src = new MemoryStream(content);
        var finalPath = await _writer.WriteAtomicAsync(
            source: src,
            targetDirectory: _options.Folders.News,
            filename: "haber08.mp3",
            expectedSha256: expectedSha,
            expectedSizeBytes: content.Length
        );

        File.Exists(finalPath).Should().BeTrue();
        (await File.ReadAllBytesAsync(finalPath)).Should().BeEquivalentTo(content);

        // Temp partial dosya kalmamalı
        Directory.GetFiles(_options.Folders.Temp, "*.partial").Should().BeEmpty();
    }

    [Fact]
    public async Task WriteAtomic_ChecksumYanlissa_TempSilinirHedefeDusmez()
    {
        var content = Encoding.UTF8.GetBytes("zarar gormesin");
        const string wrongSha = "0000000000000000000000000000000000000000000000000000000000000000";

        using var src = new MemoryStream(content);
        var act = async () => await _writer.WriteAtomicAsync(
            source: src,
            targetDirectory: _options.Folders.News,
            filename: "haber08.mp3",
            expectedSha256: wrongSha,
            expectedSizeBytes: content.Length
        );

        await act.Should().ThrowAsync<InvalidDataException>()
            .WithMessage("*Checksum uyumsuz*");

        // KRİTİK: hedef klasörde dosya OLMAMALI
        File.Exists(Path.Combine(_options.Folders.News, "haber08.mp3")).Should().BeFalse();
        // Temp da temizlenmiş olmalı
        Directory.GetFiles(_options.Folders.Temp, "*.partial").Should().BeEmpty();
    }

    [Fact]
    public async Task WriteAtomic_SizeYanlissa_TempSilinirHedefeDusmez()
    {
        var content = Encoding.UTF8.GetBytes("12345");
        var sha = Sha256Hex(content);

        using var src = new MemoryStream(content);
        var act = async () => await _writer.WriteAtomicAsync(
            source: src,
            targetDirectory: _options.Folders.News,
            filename: "haber.mp3",
            expectedSha256: sha,
            expectedSizeBytes: 9999  // wrong size
        );

        await act.Should().ThrowAsync<InvalidDataException>().WithMessage("*boyutu uyumsuz*");
        File.Exists(Path.Combine(_options.Folders.News, "haber.mp3")).Should().BeFalse();
    }

    [Theory]
    [InlineData("../etc/passwd")]
    [InlineData("..\\..\\windows\\system32\\evil.mp3")]
    [InlineData("/absolute/path.mp3")]
    [InlineData("C:\\Windows\\evil.mp3")]
    [InlineData("haber:secret.mp3")]
    public async Task WriteAtomic_PathTraversalDeneme_Reddedilir(string evilFilename)
    {
        var content = new byte[100];
        using var src = new MemoryStream(content);

        var act = async () => await _writer.WriteAtomicAsync(
            source: src,
            targetDirectory: _options.Folders.News,
            filename: evilFilename,
            expectedSha256: Sha256Hex(content),
            expectedSizeBytes: content.Length
        );

        await act.Should().ThrowAsync<ArgumentException>();
    }

    [Theory]
    [InlineData("haber.exe")]
    [InlineData("haber.bat")]
    [InlineData("haber.cmd")]
    [InlineData("haber.ps1")]
    [InlineData("haber.dll")]
    [InlineData("haber.scr")]
    public async Task WriteAtomic_GuvensizExtension_Reddedilir(string evilFilename)
    {
        var content = new byte[100];
        using var src = new MemoryStream(content);

        var act = async () => await _writer.WriteAtomicAsync(
            source: src,
            targetDirectory: _options.Folders.News,
            filename: evilFilename,
            expectedSha256: Sha256Hex(content),
            expectedSizeBytes: content.Length
        );

        await act.Should().ThrowAsync<ArgumentException>().WithMessage("*uzantısı*");
    }

    [Theory]
    [InlineData("CON.mp3")]
    [InlineData("PRN.mp3")]
    [InlineData("NUL.mp3")]
    [InlineData("COM1.mp3")]
    [InlineData("LPT9.mp3")]
    public async Task WriteAtomic_WindowsReservedName_Reddedilir(string reserved)
    {
        var content = new byte[100];
        using var src = new MemoryStream(content);

        var act = async () => await _writer.WriteAtomicAsync(
            source: src,
            targetDirectory: _options.Folders.News,
            filename: reserved,
            expectedSha256: Sha256Hex(content),
            expectedSizeBytes: content.Length
        );

        await act.Should().ThrowAsync<ArgumentException>();
    }

    [Fact]
    public void GetFreeBytes_GecerliPath_PozitifDeger()
    {
        var free = _writer.GetFreeBytes(_baseDir);
        free.Should().BeGreaterThan(0);
    }

    private static string Sha256Hex(byte[] data)
    {
        using var sha = SHA256.Create();
        // .NET 8: ToHexString → UPPER hex; lower için ToLowerInvariant
        return Convert.ToHexString(sha.ComputeHash(data)).ToLowerInvariant();
    }
}
