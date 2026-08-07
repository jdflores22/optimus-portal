using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using Optimus.Application.Auth.Interfaces;
using Optimus.Domain.Entities;
using Optimus.Domain.Enums;
using Optimus.Infrastructure.Persistence.Configurations;
using Optimus.Shared.Constants;

namespace Optimus.Infrastructure.Persistence.Seed;

public static class DbSeeder
{
    public static async Task SeedAsync(IServiceProvider services)
    {
        using var scope = services.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<OptimusDbContext>();
        var hasher = scope.ServiceProvider.GetRequiredService<IPasswordHasher>();
        var logger = scope.ServiceProvider.GetRequiredService<ILoggerFactory>().CreateLogger("DbSeeder");

        await db.Database.MigrateAsync();

        ShippingLine? demoLine = null;
        if (!await db.ShippingLines.AnyAsync())
        {
            demoLine = new ShippingLine
            {
                BrandName = "Demo Shipping Line",
                BrandColor = "#0B3D5C",
                IsActive = true
            };
            db.ShippingLines.Add(demoLine);
            await db.SaveChangesAsync();

            foreach (var (role, permission, allowed) in DefaultPermissionKeys.DefaultsFor(demoLine.Id))
            {
                db.RolePermissionConfigurations.Add(new RolePermissionConfiguration
                {
                    ShippingLineId = demoLine.Id,
                    Role = role,
                    PermissionKey = permission,
                    IsAllowed = allowed
                });
            }

            await db.SaveChangesAsync();
            logger.LogInformation("Seeded Demo Shipping Line");
        }
        else
        {
            demoLine = await db.ShippingLines.FirstAsync();
        }

        if (!await db.Users.AnyAsync(x => x.Email == "admin@optimus.local"))
        {
            // legacy empty-db path handled below via EnsureRoleUser
        }

        var password = hasher.Hash("Admin123!");
        await EnsureRoleUsersAsync(db, demoLine, password, logger);

        if (!await db.Regions.AnyAsync())
        {
            var ncr = new Region
            {
                Code = "NCR",
                Name = "National Capital Region",
                Provinces =
                {
                    new Province
                    {
                        Code = "MNL",
                        Name = "Metro Manila",
                        Cities =
                        {
                            new City
                            {
                                Code = "MKT",
                                Name = "Makati",
                                Barangays =
                                {
                                    new Barangay { Code = "MKT-PBL", Name = "Poblacion" },
                                    new Barangay { Code = "MKT-BLG", Name = "Bel-Air" },
                                    new Barangay { Code = "MKT-SND", Name = "San Lorenzo" }
                                }
                            },
                            new City
                            {
                                Code = "MNL-CITY",
                                Name = "Manila",
                                Barangays =
                                {
                                    new Barangay { Code = "MNL-ERM", Name = "Ermita" },
                                    new Barangay { Code = "MNL-MLT", Name = "Malate" }
                                }
                            },
                            new City
                            {
                                Code = "QZN",
                                Name = "Quezon City",
                                Barangays =
                                {
                                    new Barangay { Code = "QZN-DLM", Name = "Diliman" },
                                    new Barangay { Code = "QZN-CUB", Name = "Cubao" }
                                }
                            }
                        }
                    }
                }
            };

            var calabarzon = new Region
            {
                Code = "04A",
                Name = "CALABARZON",
                Provinces =
                {
                    new Province
                    {
                        Code = "CAV",
                        Name = "Cavite",
                        Cities =
                        {
                            new City
                            {
                                Code = "BACOOR",
                                Name = "Bacoor",
                                Barangays =
                                {
                                    new Barangay { Code = "BAC-MOL", Name = "Molino" },
                                    new Barangay { Code = "BAC-ZAP", Name = "Zapote" }
                                }
                            }
                        }
                    },
                    new Province
                    {
                        Code = "LAG",
                        Name = "Laguna",
                        Cities =
                        {
                            new City
                            {
                                Code = "STA",
                                Name = "Santa Rosa",
                                Barangays =
                                {
                                    new Barangay { Code = "STA-BAL", Name = "Balibago" },
                                    new Barangay { Code = "STA-DON", Name = "Don Jose" }
                                }
                            }
                        }
                    }
                }
            };

            db.Regions.AddRange(ncr, calabarzon);
            await db.SaveChangesAsync();
            logger.LogInformation("Seeded sample PH geo hierarchy.");
        }

        if (!await db.PaymentFeeConfigurations.AnyAsync(x => x.FeeType == "manifest_access" && x.IsActive))
        {
            var admin = await db.Users.FirstAsync(x => x.Email == "admin@optimus.local");
            db.PaymentFeeConfigurations.Add(new PaymentFeeConfiguration
            {
                FeeType = "manifest_access",
                Amount = 500m,
                ConfiguredById = admin.Id,
                IsActive = true
            });
            db.PaymentFeeConfigurations.Add(new PaymentFeeConfiguration
            {
                FeeType = "edo",
                Amount = 750m,
                ConfiguredById = admin.Id,
                IsActive = true
            });
            await db.SaveChangesAsync();
            logger.LogInformation("Seeded payment fee configurations.");
        }

        if (!await db.PaymentFeeConfigurations.AnyAsync(x => x.FeeType == "edo" && x.IsActive))
        {
            var admin = await db.Users.FirstAsync(x => x.Email == "admin@optimus.local");
            db.PaymentFeeConfigurations.Add(new PaymentFeeConfiguration
            {
                FeeType = "edo",
                Amount = 750m,
                ConfiguredById = admin.Id,
                IsActive = true
            });
            await db.SaveChangesAsync();
            logger.LogInformation("Seeded eDO payment fee configuration.");
        }

        await EnsureYardSeedAsync(db, logger);
        await EnsureOpsSeedAsync(db, logger);
        await EnsurePlatformSeedAsync(db, logger);
    }

    private static async Task EnsurePlatformSeedAsync(OptimusDbContext db, ILogger logger)
    {
        async Task EnsureTemplate(string key, string channel, string name, string? subject, string body)
        {
            if (await db.MessageTemplates.AnyAsync(x => x.Key == key && x.Channel == channel)) return;
            db.MessageTemplates.Add(new MessageTemplate
            {
                Key = key,
                Channel = channel,
                Name = name,
                Subject = subject,
                Body = body,
                IsActive = true
            });
        }

        await EnsureTemplate("notify.general", "email", "General notification", "{{title}}",
            "<p>Hello {{name}},</p><p><strong>{{title}}</strong></p><p>{{message}}</p>");
        await EnsureTemplate("notify.dwell", "email", "Dwell alert", "Dwell: {{title}}",
            "<p>{{name}},</p><p>Dwell alert: {{message}}</p>");
        await EnsureTemplate("notify.dwell", "sms", "Dwell SMS", null, "OPTIMUS Dwell: {{title}} — {{message}}");
        await EnsureTemplate("notify.edo", "email", "eDO notification", "{{title}}",
            "<p>{{name}},</p><p>{{message}}</p>");
        await EnsureTemplate("notify.payment", "email", "Payment notification", "{{title}}",
            "<p>{{name}},</p><p>{{message}}</p>");
        await EnsureTemplate("notify.preadvice", "sms", "Pre-advice SMS", null, "OPTIMUS: {{title}} — {{message}}");

        if (!await db.SystemSettings.AnyAsync())
        {
            db.SystemSettings.AddRange(
                new SystemSetting { Key = "session.idle_minutes", Value = "30", Description = "Idle session timeout" },
                new SystemSetting { Key = "notifications.email_from", Value = "noreply@optimus.local", Description = "Default from address" },
                new SystemSetting { Key = "pwa.vapid_public_key", Value = "DEMO_VAPID_PUBLIC", Description = "Web Push VAPID public key (demo)" }
            );
        }

        if (!await db.RateLimitRules.AnyAsync())
        {
            db.RateLimitRules.Add(new RateLimitRule
            {
                Name = "Global API",
                PathPrefix = "/api",
                PermitLimit = 180,
                WindowSeconds = 60,
                IsActive = true
            });
            db.RateLimitRules.Add(new RateLimitRule
            {
                Name = "Auth endpoints",
                PathPrefix = "/api/auth",
                PermitLimit = 30,
                WindowSeconds = 60,
                IsActive = true
            });
        }

        if (!await db.DocumentTemplates.AnyAsync())
        {
            var admin = await db.Users.FirstOrDefaultAsync(x => x.Email == "admin@optimus.local");
            foreach (var type in new[] { "NOA", "EDO", "BL", "Billing", "OR", "Certificate" })
            {
                db.DocumentTemplates.Add(new DocumentTemplate
                {
                    DocumentType = type,
                    Name = $"{type} default",
                    Version = 1,
                    BodyHtml = $"<h1>{type}</h1><p>{{{{content}}}}</p>",
                    IsActive = true,
                    UpdatedById = admin?.Id
                });
            }
        }

        if (!await db.ScheduledReports.AnyAsync(x => x.ReportType == "edo_release_metrics"))
        {
            db.ScheduledReports.Add(new ScheduledReport
            {
                ReportType = "edo_release_metrics",
                CronExpression = "0 2 * * *",
                RecipientsJson = "[\"admin@optimus.local\"]",
                IsActive = true
            });
        }

        await db.SaveChangesAsync();
        logger.LogInformation("Ensured Phase 6 platform seed (templates/settings/rate limits/reports).");
    }

    private static async Task EnsureOpsSeedAsync(OptimusDbContext db, ILogger logger)
    {
        var admin = await db.Users.FirstOrDefaultAsync(x => x.Email == "admin@optimus.local");
        if (admin is null) return;

        async Task EnsureForm(FormConfigType type, string name)
        {
            if (await db.FormConfigurations.AnyAsync(x => x.Type == type && x.Status == FormConfigStatus.Active))
            {
                return;
            }

            db.FormConfigurations.Add(new FormConfiguration
            {
                Name = name,
                Type = type,
                Version = 1,
                Status = FormConfigStatus.Active,
                FieldsJson = """{"fields":[{"id":"company","label":"Company Name","type":"text","required":true,"order":1},{"id":"tin","label":"TIN","type":"text","required":true,"order":2},{"id":"address","label":"Business Address","type":"text","required":false,"order":3}]}""",
                PublishedAt = DateTime.UtcNow,
                CreatedById = admin.Id
            });
        }

        await EnsureForm(FormConfigType.Broker, "Broker SAS Form");
        await EnsureForm(FormConfigType.Consignee, "Consignee SAS Form");

        if (!await db.WelcomeContents.AnyAsync(x => x.Audience == "Consignee" && x.IsActive))
        {
            db.WelcomeContents.Add(new WelcomeContent
            {
                Audience = "Consignee",
                Title = "Welcome, Consignee",
                BodyMarkdown = "Get accredited, link brokers, and generate referral codes.",
                StepsJson = """["submit_accreditation","link_brokers","generate_referral_code"]""",
                IsActive = true
            });
        }

        await db.SaveChangesAsync();

        if (!await db.RepositioningRequests.AnyAsync())
        {
            var line = await db.ShippingLines.FirstOrDefaultAsync();
            var cy = await db.Terminals.FirstOrDefaultAsync(x => x.Code == "CY-MNL");
            var port = await db.Terminals.FirstOrDefaultAsync(x => x.Code == "ATI-MNL");
            var staff = await db.Users.FirstOrDefaultAsync(x => x.Email == "slstaff@optimus.local")
                        ?? await db.Users.FirstOrDefaultAsync(x => x.Email == "sladmin@optimus.local");
            var containers = await db.Containers
                .Where(x => x.AllocationStatus == AllocationStatus.Allocated || x.AllocationStatus == AllocationStatus.PreForecast)
                .OrderByDescending(x => x.CurrentDwellDays)
                .Take(2)
                .ToListAsync();

            if (line is not null && cy is not null && port is not null && staff is not null && containers.Count > 0)
            {
                var year = DateTime.UtcNow.Year;
                var request = new RepositioningRequest
                {
                    RequestNumber = $"RRP-{year}-00001",
                    ShippingLineId = line.Id,
                    RequestType = RepositioningRequestType.Export,
                    SourceTerminalId = cy.Id,
                    DestinationTerminalId = port.Id,
                    Purpose = "Export reposition of high-dwell empties to ATI for vessel loading.",
                    ContainerCount = containers.Count,
                    Status = RepositioningStatus.Pending,
                    RequestedById = staff.Id,
                    RequestedAt = DateTime.UtcNow.AddHours(-6)
                };
                foreach (var c in containers)
                {
                    request.Items.Add(new RepositioningRequestItem
                    {
                        ContainerId = c.Id,
                        DwellTimeDays = c.CurrentDwellDays,
                        DischargeDate = c.TerminalArrivalDate
                    });
                }

                db.RepositioningRequests.Add(request);
                await db.SaveChangesAsync();
            }
        }

        logger.LogInformation("Ensured Phase 5 ops seed data.");
    }

    private static async Task EnsureYardSeedAsync(OptimusDbContext db, ILogger logger)
    {
        if (!await db.ContainerTypes.AnyAsync())
        {
            db.ContainerTypes.AddRange(
                new ContainerType { Name = "Dry", Code = "DRY", Description = "General purpose" },
                new ContainerType { Name = "Reefer", Code = "REEFER", Description = "Refrigerated" });
        }

        if (!await db.ContainerSizes.AnyAsync())
        {
            db.ContainerSizes.AddRange(
                new ContainerSize { Name = "20 Foot", Code = "20FT", TeuValue = 1m },
                new ContainerSize { Name = "40 Foot", Code = "40FT", TeuValue = 2m });
        }

        if (!await db.DwellTimeConfigurations.AnyAsync(x => x.IsActive))
        {
            db.DwellTimeConfigurations.Add(new DwellTimeConfiguration());
        }

        Terminal? terminal;
        if (!await db.Terminals.AnyAsync(x => x.Code == "CY-MNL"))
        {
            terminal = new Terminal
            {
                Name = "Manila Container Yard",
                Code = "CY-MNL",
                Identity = TerminalIdentity.ContainerYard,
                Kind = TerminalKind.Cy,
                Location = "Manila South Harbor",
                Region = "NCR",
                City = "Manila",
                DailyCapacity = 200,
                IsActive = true
            };
            db.Terminals.Add(terminal);
            await db.SaveChangesAsync();
            db.TerminalSlots.Add(new TerminalSlot
            {
                TerminalId = terminal.Id,
                Date = DateOnly.FromDateTime(DateTime.UtcNow.Date),
                Capacity = 50,
                AssignedCount = 0,
                Status = SlotStatus.Available
            });
        }
        else
        {
            terminal = await db.Terminals.FirstAsync(x => x.Code == "CY-MNL");
        }

        var line = await db.ShippingLines.FirstOrDefaultAsync();
        var staff = await db.Users.FirstOrDefaultAsync(x => x.Email == "slstaff@optimus.local");
        if (line is not null &&
            !await db.ShippingLineTerminalAllocations.AnyAsync(x => x.ShippingLineId == line.Id && x.TerminalId == terminal.Id))
        {
            db.ShippingLineTerminalAllocations.Add(new ShippingLineTerminalAllocation
            {
                ShippingLineId = line.Id,
                TerminalId = terminal.Id,
                StaffUserId = staff?.Id,
                AllocatedCapacityTeu = 100,
                Capacity20Ft = 60,
                Capacity40Ft = 20
            });
        }

        Terminal? seaTerminal;
        if (!await db.Terminals.AnyAsync(x => x.Code == "ATI-MNL"))
        {
            seaTerminal = new Terminal
            {
                Name = "ATI Manila Terminal",
                Code = "ATI-MNL",
                Identity = TerminalIdentity.Terminal,
                Kind = TerminalKind.Ati,
                Location = "Manila North Harbor",
                Region = "NCR",
                City = "Manila",
                DailyCapacity = 150,
                IsActive = true
            };
            db.Terminals.Add(seaTerminal);
            await db.SaveChangesAsync();
        }
        else
        {
            seaTerminal = await db.Terminals.FirstAsync(x => x.Code == "ATI-MNL");
        }

        if (line is not null &&
            !await db.ShippingLineTerminalAllocations.AnyAsync(x => x.ShippingLineId == line.Id && x.TerminalId == seaTerminal.Id))
        {
            db.ShippingLineTerminalAllocations.Add(new ShippingLineTerminalAllocation
            {
                ShippingLineId = line.Id,
                TerminalId = seaTerminal.Id,
                StaffUserId = staff?.Id,
                AllocatedCapacityTeu = 80,
                Capacity20Ft = 40,
                Capacity40Ft = 20
            });
        }

        await db.SaveChangesAsync();

        if (line is not null)
        {
            var dry = await db.ContainerTypes.FirstAsync(x => x.Code == "DRY");
            var size20 = await db.ContainerSizes.FirstAsync(x => x.Code == "20FT");
            var size40 = await db.ContainerSizes.FirstAsync(x => x.Code == "40FT");
            var cyAlloc = await db.ShippingLineTerminalAllocations
                .FirstAsync(x => x.ShippingLineId == line.Id && x.TerminalId == terminal.Id);
            var atiAlloc = await db.ShippingLineTerminalAllocations
                .FirstAsync(x => x.ShippingLineId == line.Id && x.TerminalId == seaTerminal.Id);

            var now = DateTime.UtcNow;
            var samples = new[]
            {
                new Container
                {
                    ContainerNumber = "MAEU8666379",
                    ShippingLineId = line.Id,
                    ContainerTypeId = dry.Id,
                    ContainerSizeId = size40.Id,
                    Status = ContainerStatus.AtTerminal,
                    AllocationStatus = AllocationStatus.Allocated,
                    CyAllocationId = cyAlloc.Id,
                    AllocatedAt = now.AddDays(-14),
                    AllocationLockedAt = now.AddDays(-13),
                    TerminalArrivalDate = now.AddDays(-11),
                    CurrentDwellDays = 11,
                    CurrentLocation = "CY-MNL",
                    StackBay = "D",
                    StackRow = "07",
                    StackTier = "3"
                },
                new Container
                {
                    ContainerNumber = "MSCU1234567",
                    ShippingLineId = line.Id,
                    ContainerTypeId = dry.Id,
                    ContainerSizeId = size20.Id,
                    Status = ContainerStatus.AtTerminal,
                    AllocationStatus = AllocationStatus.Allocated,
                    CyAllocationId = cyAlloc.Id,
                    AllocatedAt = now.AddDays(-12),
                    AllocationLockedAt = now.AddDays(-10),
                    TerminalArrivalDate = now.AddDays(-9),
                    CurrentDwellDays = 9,
                    CurrentLocation = "CY-MNL",
                    StackBay = "A",
                    StackRow = "01",
                    StackTier = "1"
                },
                new Container
                {
                    ContainerNumber = "MSCU7654321",
                    ShippingLineId = line.Id,
                    ContainerTypeId = dry.Id,
                    ContainerSizeId = size40.Id,
                    Status = ContainerStatus.AvailableForReturn,
                    AllocationStatus = AllocationStatus.Allocated,
                    CyAllocationId = cyAlloc.Id,
                    AllocatedAt = now.AddDays(-20),
                    AllocationLockedAt = now.AddDays(-18),
                    TerminalArrivalDate = now.AddDays(-16),
                    CurrentDwellDays = 16,
                    CurrentLocation = "CY-MNL",
                    StackBay = "B",
                    StackRow = "03",
                    StackTier = "2"
                },
                new Container
                {
                    ContainerNumber = "TGHU9988776",
                    ShippingLineId = line.Id,
                    ContainerTypeId = dry.Id,
                    ContainerSizeId = size20.Id,
                    Status = ContainerStatus.Pending,
                    AllocationStatus = AllocationStatus.PreForecast,
                    CyAllocationId = atiAlloc.Id,
                    AllocatedAt = now.AddDays(-3),
                    CurrentDwellDays = 0,
                    CurrentLocation = "ATI-MNL"
                },
                new Container
                {
                    ContainerNumber = "HLCU5544332",
                    ShippingLineId = line.Id,
                    ContainerTypeId = dry.Id,
                    ContainerSizeId = size40.Id,
                    Status = ContainerStatus.Alert,
                    AllocationStatus = AllocationStatus.Allocated,
                    CyAllocationId = atiAlloc.Id,
                    AllocatedAt = now.AddDays(-30),
                    AllocationLockedAt = now.AddDays(-28),
                    TerminalArrivalDate = now.AddDays(-25),
                    CurrentDwellDays = 25,
                    TotalPausedDays = 2,
                    CurrentLocation = "ATI-MNL",
                    StackBay = "C",
                    StackRow = "12",
                    StackTier = "1"
                },
                new Container
                {
                    ContainerNumber = "OOLU1122334",
                    ShippingLineId = line.Id,
                    ContainerTypeId = dry.Id,
                    ContainerSizeId = size20.Id,
                    Status = ContainerStatus.Maintenance,
                    AllocationStatus = AllocationStatus.Allocated,
                    CyAllocationId = cyAlloc.Id,
                    AllocatedAt = now.AddDays(-8),
                    AllocationLockedAt = now.AddDays(-7),
                    TerminalArrivalDate = now.AddDays(-5),
                    CurrentDwellDays = 5,
                    CurrentLocation = "CY-MNL"
                }
            };

            foreach (var sample in samples)
            {
                if (!await db.Containers.AnyAsync(x => x.ContainerNumber == sample.ContainerNumber))
                {
                    db.Containers.Add(sample);
                }
            }

            await db.SaveChangesAsync();
        }

        logger.LogInformation("Ensured Phase 4 yard seed data.");
    }

    private static async Task EnsureRoleUsersAsync(
        OptimusDbContext db,
        ShippingLine demoLine,
        string passwordHash,
        ILogger logger)
    {
        async Task<T> EnsureUser<T>(string email, Func<T> factory) where T : User
        {
            var existing = await db.Users.FirstOrDefaultAsync(x => x.Email == email);
            if (existing is T typed)
            {
                return typed;
            }

            if (existing is not null)
            {
                return (T)existing;
            }

            var created = factory();
            created.Email = email;
            created.PasswordHash = passwordHash;
            created.Status = AccountStatus.Approved;
            created.EmailVerified = true;
            created.EmailVerifiedAt = DateTime.UtcNow;
            created.IsActive = true;
            db.Set<T>().Add(created);
            await db.SaveChangesAsync();
            return created;
        }

        var admin = await EnsureUser("admin@optimus.local", () => new User
        {
            FirstName = "System",
            LastName = "Admin",
            Role = AppRoles.SystemAdmin,
            UserType = UserType.SystemAdmin
        });

        var slAdmin = await EnsureUser("sladmin@optimus.local", () => new StaffUser
        {
            FirstName = "Shipping",
            LastName = "Admin",
            Role = AppRoles.ShippingLinesAdmin,
            UserType = UserType.Staff,
            Department = "Operations",
            ManagedShippingLineId = demoLine.Id
        });

        var slStaff = await EnsureUser("slstaff@optimus.local", () => new StaffUser
        {
            FirstName = "SL",
            LastName = "Staff",
            Role = AppRoles.SlStaff,
            UserType = UserType.Staff,
            Department = "Manifest",
            ShippingLineAdminId = slAdmin.Id
        });

        var evaluator = await EnsureUser("evaluator@optimus.local", () => new StaffUser
        {
            FirstName = "Eva",
            LastName = "Luator",
            Role = AppRoles.Evaluator,
            UserType = UserType.Staff,
            Department = "Compliance",
            ShippingLineAdminId = slAdmin.Id
        });

        var accounting = await EnsureUser("accounting@optimus.local", () => new StaffUser
        {
            FirstName = "Ann",
            LastName = "Counting",
            Role = AppRoles.Accounting,
            UserType = UserType.Staff,
            Department = "Finance",
            ShippingLineAdminId = slAdmin.Id
        });

        var terminal = await EnsureUser("terminal@optimus.local", () => new TerminalTeamUser
        {
            FirstName = "Terry",
            LastName = "Terminal",
            Role = AppRoles.TerminalTeam,
            UserType = UserType.TerminalTeam,
            Department = "Gate",
            ShippingLineAdminId = slAdmin.Id
        });

        var broker = await EnsureUser("broker@optimus.local", () => new Broker
        {
            FirstName = "Bobby",
            LastName = "Broker",
            BusinessAddress = "Makati",
            Role = AppRoles.Broker,
            UserType = UserType.Broker
        });

        var consignee = await EnsureUser("consignee@optimus.local", () => new Consignee
        {
            FirstName = "Connie",
            LastName = "Consignee",
            BusinessName = "Connie Trading",
            Role = AppRoles.Consignee,
            UserType = UserType.Consignee
        });

        var trucker = await EnsureUser("trucker@optimus.local", () => new Trucker
        {
            FirstName = "Tom",
            LastName = "Trucker",
            CompanyName = "Tom Hauling",
            Role = AppRoles.Trucker,
            UserType = UserType.Trucker
        });

        demoLine.AssignedAdminUserId ??= slAdmin.Id;
        slAdmin.ManagedShippingLineId ??= demoLine.Id;

        if (!await db.ReferralCodes.AnyAsync(x => x.Code == "DEMOREF01"))
        {
            var referral = new ReferralCode
            {
                ConsigneeId = consignee.Id,
                Code = "DEMOREF01",
                IsActive = true,
                CreatedByUserId = consignee.Id,
                MaxUses = 100
            };
            db.ReferralCodes.Add(referral);
            await db.SaveChangesAsync();

            if (!await db.ConsigneeBrokerRelationships.AnyAsync(x => x.BrokerId == broker.Id && x.ConsigneeId == consignee.Id))
            {
                db.ConsigneeBrokerRelationships.Add(new ConsigneeBrokerRelationship
                {
                    ConsigneeId = consignee.Id,
                    BrokerId = broker.Id,
                    ReferralCodeId = referral.Id,
                    Status = RelationshipStatus.Active
                });
            }
        }

        if (broker is Broker brokerEntity)
        {
            brokerEntity.ActiveWorkspaceConsigneeId ??= consignee.Id;
        }

        foreach (var user in new User[] { admin, slAdmin, slStaff, evaluator, accounting, terminal, broker, consignee, trucker })
        {
            if (!await db.UserShippingLinePreferences.AnyAsync(x => x.UserId == user.Id))
            {
                db.UserShippingLinePreferences.Add(new UserShippingLinePreference
                {
                    UserId = user.Id,
                    LastSelectedShippingLineId = demoLine.Id
                });
            }
        }

        await db.SaveChangesAsync();
        logger.LogInformation("Ensured Phase 1 role users (password Admin123!)");
    }
}
