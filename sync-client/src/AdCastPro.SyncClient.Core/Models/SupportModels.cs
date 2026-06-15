namespace AdCastPro.SyncClient.Core.Models;

/// <summary>
/// Destek talebi isteği — POST /api/v1/sync/support.
/// Radyo bilgileri sunucuda JWT'den dogrulanir; buradan gonderilmez.
/// </summary>
public sealed record SupportTicketRequest(
    string FullName,
    string Phone,
    string Email,
    string Message
);
