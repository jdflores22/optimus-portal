using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace Optimus.Infrastructure.Persistence.Migrations
{
    /// <inheritdoc />
    public partial class Phase7Hardening : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            // Do not drop FK-backed indexes on MySQL (InnoDB requires them).
            migrationBuilder.CreateIndex(
                name: "IX_rate_limit_rules_IsActive_PathPrefix",
                table: "rate_limit_rules",
                columns: new[] { "IsActive", "PathPrefix" });

            migrationBuilder.CreateIndex(
                name: "IX_pre_advice_requests_Status",
                table: "pre_advice_requests",
                column: "Status");

            migrationBuilder.CreateIndex(
                name: "IX_payments_ManifestId_Status",
                table: "payments",
                columns: new[] { "ManifestId", "Status" });

            migrationBuilder.CreateIndex(
                name: "IX_payments_Status",
                table: "payments",
                column: "Status");

            migrationBuilder.CreateIndex(
                name: "IX_manifests_ShippingLineId_WorkflowState",
                table: "manifests",
                columns: new[] { "ShippingLineId", "WorkflowState" });

            migrationBuilder.CreateIndex(
                name: "IX_manifests_WorkflowState",
                table: "manifests",
                column: "WorkflowState");

            migrationBuilder.CreateIndex(
                name: "IX_electronic_delivery_orders_Status",
                table: "electronic_delivery_orders",
                column: "Status");

            migrationBuilder.CreateIndex(
                name: "IX_electronic_delivery_orders_Status_ReleasedAt",
                table: "electronic_delivery_orders",
                columns: new[] { "Status", "ReleasedAt" });
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropIndex(
                name: "IX_rate_limit_rules_IsActive_PathPrefix",
                table: "rate_limit_rules");

            migrationBuilder.DropIndex(
                name: "IX_pre_advice_requests_Status",
                table: "pre_advice_requests");

            migrationBuilder.DropIndex(
                name: "IX_payments_ManifestId_Status",
                table: "payments");

            migrationBuilder.DropIndex(
                name: "IX_payments_Status",
                table: "payments");

            migrationBuilder.DropIndex(
                name: "IX_manifests_ShippingLineId_WorkflowState",
                table: "manifests");

            migrationBuilder.DropIndex(
                name: "IX_manifests_WorkflowState",
                table: "manifests");

            migrationBuilder.DropIndex(
                name: "IX_electronic_delivery_orders_Status",
                table: "electronic_delivery_orders");

            migrationBuilder.DropIndex(
                name: "IX_electronic_delivery_orders_Status_ReleasedAt",
                table: "electronic_delivery_orders");
        }
    }
}
